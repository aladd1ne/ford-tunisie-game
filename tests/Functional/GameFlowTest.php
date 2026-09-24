<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Participant;
use App\Entity\Prize;
use App\Enum\PrizeType;
use App\Exception\Game\AlreadyPlayedException;
use App\Repository\ParticipantRepository;
use App\Service\Game\PlayAuthorization;
use App\Service\Game\SpinResultPresenter;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\TestRandomNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parcours complet : accueil → inscription → confirmation, puis, une fois le
 * participant autorisé depuis le back-office, roue → résultat.
 */
final class GameFlowTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->resetDatabase($this->entityManager);

        // La roue tire désormais à pile ou face avant même de désigner un lot
        // (voir SpinService) : ces tests portent sur le parcours HTTP, pas sur
        // la probabilité de gain, donc le pile ou face est forcé côté gagnant
        // par défaut. Les tests dédiés à la case « perdu » le forcent à leur
        // tour côté perdant.
        $this->forceWinningCoinFlip();
    }

    protected function tearDown(): void
    {
        TestRandomNumberGenerator::reset();

        parent::tearDown();
    }

    public function testLandingPagePresentsTheGame(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'La Roue Ford');
        self::assertSelectorTextContains(
            '.hero__lead',
            'Inscrivez-vous, présentez-vous à l’accueil, puis faites tourner la roue pour tenter de gagner un cadeau !',
        );
        self::assertSame('Participer', trim($crawler->filter('.hero a.btn')->text()));
        self::assertSame('/inscription', $crawler->filter('.hero a.btn')->attr('href'));
    }

    public function testRegistrationPageShowsTheExpectedFields(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inscrivez-vous pour jouer');

        $labels = $crawler->filter('.field__label')->each(static fn (Crawler $node): string => trim($node->text()));

        self::assertSame(['Prénom', 'Nom', 'Société ou agence', 'Adresse e-mail', 'Téléphone'], $labels);
        self::assertCount(1, $crawler->filter('input[name="registration[_token]"]'), 'Le formulaire doit être protégé par un jeton CSRF.');
    }

    public function testRegistrationRejectsInvalidDataWithFrenchMessages(): void
    {
        $crawler = $this->submitRegistration([
            'registration[firstName]' => '',
            'registration[lastName]' => '',
            'registration[company]' => '',
            'registration[email]' => 'pas-un-email',
            'registration[phone]' => 'appelez-moi',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $errors = $crawler->filter('.field__error')->each(static fn (Crawler $node): string => trim($node->text()));

        self::assertContains('Veuillez saisir votre prénom.', $errors);
        self::assertContains('Veuillez saisir votre nom.', $errors);
        self::assertContains('Veuillez indiquer votre société ou votre agence.', $errors);
        self::assertContains("Le numéro de téléphone n'est pas valide.", $errors);
        self::assertStringContainsString("L'adresse e-mail", implode(' ', $errors));

        self::assertSame(0, $this->countRows('participant'), 'Aucun participant ne doit être créé.');
    }

    public function testRegistrationIsRejectedWithoutAValidCsrfToken(): void
    {
        $this->client->request('POST', '/inscription', [
            'registration' => [
                'firstName' => 'Marc',
                'lastName' => 'Dupont',
                'company' => 'Agence Nord',
                'email' => 'marc.dupont@exemple.fr',
                'phone' => '',
                '_token' => 'jeton-invalide',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->countRows('participant'));
    }

    public function testPhoneIsOptional(): void
    {
        $this->submitRegistration([
            'registration[firstName]' => 'Marc',
            'registration[lastName]' => 'Dupont',
            'registration[company]' => 'Agence Nord',
            'registration[email]' => 'marc.dupont@exemple.fr',
            'registration[phone]' => '',
        ]);

        self::assertResponseRedirects('/inscription/confirmation');
        self::assertSame(1, $this->countRows('participant'));
    }

    public function testRegistrationEndsOnAConfirmationWithoutGrantingAccessToTheWheel(): void
    {
        $this->register();

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inscription confirmée, Marc !');
        self::assertSelectorTextContains('.panel__lead', 'Présentez-vous à l’accueil');
        self::assertCount(0, $crawler->filter('form'), 'La confirmation ne contient aucun formulaire.');
        self::assertCount(0, $crawler->filter('a[href="/jeu"]'), 'La confirmation ne mène pas à la roue.');

        // Inscrit mais pas encore autorisé : la roue attend toujours.
        $this->client->request('GET', '/jeu');

        self::assertNull($this->wheelData()['player']);
    }

    public function testConfirmationPageRequiresARegistration(): void
    {
        $this->client->request('GET', '/inscription/confirmation');

        self::assertResponseRedirects('/inscription');
    }

    public function testWheelIsPublicWithoutRegistrationFormAndWaitsForAPlayer(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);

        $crawler = $this->client->request('GET', '/jeu');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form'), 'La roue ne contient aucun formulaire.');
        self::assertCount(0, $crawler->filter('input'), 'La roue ne contient aucun champ de saisie.');
        self::assertSelectorTextContains('#roue-joueur', 'En attente du prochain joueur');
        self::assertNotNull($crawler->filter('#roue-bouton')->attr('disabled'));
        self::assertNull($crawler->filter('#roue-attente')->attr('hidden'));
        self::assertNull($this->wheelData()['player']);
    }

    public function testWheelNeverOffersToReplay(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $participant = $this->register();
        $this->authorize($participant);

        $crawler = $this->client->request('GET', '/jeu');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsStringIgnoringCase('rejouer', $html);
        self::assertStringNotContainsString('Nouveau joueur', $html);
        self::assertCount(0, $crawler->filter('#roue-rejouer'));
        self::assertSame('Terminer', trim($crawler->filter('#roue-terminer')->text()));
    }

    public function testPlayerEndpointReturnsTheParticipantAuthorizedFromTheBackOffice(): void
    {
        $this->client->request('GET', '/jeu/joueur');

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['data']['player']);

        $participant = $this->register();
        $this->authorize($participant);

        $this->client->request('GET', '/jeu/joueur');

        self::assertSame(
            ['uuid' => (string) $participant->getUuid(), 'firstName' => 'Marc'],
            $this->json()['data']['player'],
        );
    }

    /**
     * Si l'équipe autorise plusieurs visiteurs d'affilée, la roue accueille
     * le dernier autorisé ; les autres restent en attente tant qu'ils n'ont
     * pas joué.
     */
    public function testTheLatestAuthorizedParticipantIsTheExpectedPlayer(): void
    {
        $this->createPrize('Casquette Ford', 10);
        $marc = $this->register();
        $claire = $this->register('claire.martin@exemple.fr', 'Claire');

        $this->authorize($marc);
        $this->authorize($claire);

        $this->client->request('GET', '/jeu');
        self::assertSame((string) $claire->getUuid(), $this->wheelData()['player']['uuid']);
        self::assertSelectorTextContains('#roue-joueur', 'Bonjour Claire');

        $this->spin($this->wheelData()['csrfToken'], (string) $claire->getUuid());

        $this->client->request('GET', '/jeu');
        self::assertSame((string) $marc->getUuid(), $this->wheelData()['player']['uuid']);
    }

    public function testSpinIsRefusedWithoutAnAuthorization(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $participant = $this->register();

        $payload = $this->spin($this->openWheelAndReadSpinToken(), (string) $participant->getUuid());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('fail', $payload['status']);
        self::assertSame(0, $this->countRows('spin'));
        self::assertSame(5, $this->stockOf('Casquette Ford'));
    }

    public function testSpinIsRefusedForAnUnknownParticipant(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);

        $this->spin($this->openWheelAndReadSpinToken(), 'inconnu');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countRows('spin'));
    }

    public function testSpinIsRefusedWithoutACsrfToken(): void
    {
        $this->createPrize('Casquette Ford', 10);
        $participant = $this->register();
        $this->authorize($participant);

        $this->client->request('POST', '/jeu/tourner', ['participant' => (string) $participant->getUuid()]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countRows('spin'));
    }

    public function testCompleteFlowAwardsAPrizeAndReturnsTheWheelToWaiting(): void
    {
        $this->createPrize('Ford Puma un week-end', 10, 5, PrizeType::MAIN);
        $participant = $this->register();
        $this->authorize($participant);

        $crawler = $this->client->request('GET', '/jeu');

        self::assertSelectorTextContains('#roue-joueur', 'Bonjour Marc');
        self::assertNull($crawler->filter('#roue-bouton')->attr('disabled'), 'Le joueur autorisé peut faire tourner la roue.');

        $payload = $this->spin($this->wheelData()['csrfToken'], (string) $participant->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame('success', $payload['status']);
        self::assertSame('Félicitations, Marc !', $payload['data']['title']);
        self::assertSame('Vous avez gagné : Ford Puma un week-end', $payload['data']['detail']);
        self::assertSame('Merci d’avoir participé à La Roue Ford !', $payload['data']['thanks']);
        self::assertArrayNotHasKey('canRetry', $payload['data']);
        self::assertSame(1, $this->countRows('spin'));

        // Le participant a joué : la roue revient en attente du suivant.
        $crawler = $this->client->request('GET', '/jeu');

        self::assertNull($this->wheelData()['player']);
        self::assertSelectorTextContains('#roue-joueur', 'En attente du prochain joueur');
        self::assertNotNull($crawler->filter('#roue-bouton')->attr('disabled'));
    }

    public function testConsolationPrizeShowsTheSameWinMessageAsTheMainPrize(): void
    {
        $this->createPrize('Porte-clés Ford', 10, null, PrizeType::CONSOLATION);
        $participant = $this->register();
        $this->authorize($participant);

        $payload = $this->spin($this->openWheelAndReadSpinToken(), (string) $participant->getUuid());

        self::assertSame(SpinResultPresenter::WIN_BADGE, $payload['data']['badge']);
        self::assertSame('Félicitations, Marc !', $payload['data']['title']);
        self::assertTrue($payload['data']['isWin']);
        self::assertSame('Vous avez gagné : Porte-clés Ford', $payload['data']['detail']);
    }

    /**
     * Chaque tirage consomme son propre jeton CSRF (nextSpinToken) : un rejeu
     * réseau ou un retour arrière du navigateur qui soumet deux fois la même
     * requête ne doit donc produire qu'un seul tirage.
     */
    public function testResubmittingTheSameSpinTokenAfterASuccessIsRejected(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $participant = $this->register();
        $this->authorize($participant);

        $token = $this->openWheelAndReadSpinToken();

        $first = $this->spin($token, (string) $participant->getUuid());
        self::assertSame('success', $first['status']);

        $this->spin($token, (string) $participant->getUuid());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(1, $this->countRows('spin'), 'Le jeton déjà utilisé ne doit pas produire un second tirage.');
        self::assertSame(4, $this->stockOf('Casquette Ford'), 'Le stock ne doit être décrémenté qu\'une fois.');
    }

    /**
     * Un seul tirage par participant, qu'il gagne ou non : une case « perdu »
     * est définitive, et le back-office ne peut plus l'autoriser à rejouer.
     */
    public function testParticipantPlaysOnlyOnceEvenAfterALoss(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $participant = $this->register();
        $this->authorize($participant);
        $this->forceLosingCoinFlip();

        $loss = $this->spin($this->openWheelAndReadSpinToken(), (string) $participant->getUuid());

        self::assertFalse($loss['data']['isWin']);
        self::assertSame(1, $this->countRows('spin'));

        // Nouvelle tentative avec un jeton valide : refusée.
        $this->forceWinningCoinFlip();
        $again = $this->spin($loss['data']['nextSpinToken'], (string) $participant->getUuid());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('fail', $again['status']);
        self::assertSame(1, $this->countRows('spin'));
        self::assertSame(5, $this->stockOf('Casquette Ford'));

        // Le back-office ne peut pas non plus lui redonner la main.
        $this->expectException(AlreadyPlayedException::class);
        $this->authorize($participant);
    }

    public function testClientCannotForceAPrize(): void
    {
        // Le lot principal est présent mais non tirable (poids 0).
        $unreachable = $this->createPrize('Ford Puma un week-end', 0, 5, PrizeType::MAIN);
        $this->createPrize('Porte-clés Ford', 10, null, PrizeType::CONSOLATION);
        $participant = $this->register();
        $this->authorize($participant);

        $token = $this->openWheelAndReadSpinToken();

        $this->client->request(
            'POST',
            '/jeu/tourner',
            [
                'participant' => (string) $participant->getUuid(),
                'prizeUuid' => (string) $unreachable->getUuid(),
                'prize' => 'Ford Puma un week-end',
            ],
            [],
            ['HTTP_X-CSRF-Token' => $token],
        );

        $payload = $this->json();

        self::assertSame('success', $payload['status']);
        self::assertSame('Porte-clés Ford', $payload['data']['prizeName'], 'Le client ne doit pas pouvoir imposer un lot.');
        self::assertSame(5, $this->stockOf('Ford Puma un week-end'));
    }

    /**
     * Même un tirage gagnant devient une case perdu si la dotation est
     * épuisée : la roue reste jouable, elle ne renvoie plus d'erreur bloquante.
     */
    public function testSpinBecomesALossWhenNoPrizeIsAvailable(): void
    {
        $this->createPrize('Épuisé', 10, 0);
        $participant = $this->register();
        $this->authorize($participant);

        $payload = $this->spin($this->openWheelAndReadSpinToken(), (string) $participant->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame('success', $payload['status']);
        self::assertNull($payload['data']['prizeUuid']);
        self::assertFalse($payload['data']['isWin']);
        self::assertSame(SpinResultPresenter::LOSS_BADGE, $payload['data']['badge']);
        self::assertSame(1, $this->countRows('spin'));
    }

    public function testWheelExposesAsManyLossSegmentsAsPrizes(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $this->createPrize('Mug Ford', 10, 5);
        $this->createPrize('Lot retiré', 10, 5, PrizeType::CONSOLATION, false);

        $this->client->request('GET', '/jeu');
        $data = $this->wheelData();

        self::assertCount(4, $data['segments'], 'Autant de cases perdu que de lots.');
        self::assertSame(
            ['Casquette Ford', 'Perdu', 'Mug Ford', 'Perdu'],
            array_column($data['segments'], 'name'),
        );
        self::assertSame(
            ['prize', 'loss', 'prize', 'loss'],
            array_column($data['segments'], 'type'),
        );
    }

    public function testLosingSpinShowsTheOopsMessageWithNoPrizeAndConsumesNoStock(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $participant = $this->register();
        $this->authorize($participant);
        $this->forceLosingCoinFlip();

        $payload = $this->spin($this->openWheelAndReadSpinToken(), (string) $participant->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame('success', $payload['status']);
        self::assertNull($payload['data']['prizeUuid']);
        self::assertNull($payload['data']['prizeName']);
        self::assertFalse($payload['data']['isWin']);
        self::assertSame(SpinResultPresenter::LOSS_BADGE, $payload['data']['badge']);
        self::assertSame(SpinResultPresenter::LOSS_TITLE, $payload['data']['title']);
        self::assertNull($payload['data']['detail']);
        self::assertSame(1, $this->countRows('spin'));
        self::assertSame(5, $this->stockOf('Casquette Ford'), 'Une case perdu ne doit consommer aucun stock.');
    }

    /* ------------------------------------------------------------------ */

    private function submitRegistration(array $values): Crawler
    {
        $crawler = $this->client->request('GET', '/inscription');
        $form = $crawler->filter('form.form')->form();

        return $this->client->submit($form, $values);
    }

    private function register(string $email = 'marc.dupont@exemple.fr', string $firstName = 'Marc'): Participant
    {
        $this->submitRegistration([
            'registration[firstName]' => $firstName,
            'registration[lastName]' => 'Dupont',
            'registration[company]' => 'Agence Nord',
            'registration[email]' => $email,
            'registration[phone]' => '+33 6 12 34 56 78',
        ]);

        self::assertResponseRedirects('/inscription/confirmation');

        $participant = self::getContainer()->get(ParticipantRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Participant::class, $participant);

        return $participant;
    }

    /**
     * Ce que fait l'équipe à l'entrée, depuis le back-office.
     *
     * Le participant est relu dans le conteneur courant : le client redémarre
     * le noyau entre deux requêtes, l'instance reçue peut donc être détachée.
     */
    private function authorize(Participant $participant): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        self::getContainer()->get(PlayAuthorization::class)->authorize(
            $entityManager->find(Participant::class, $participant->getId()),
        );
    }

    private function openWheelAndReadSpinToken(): string
    {
        $this->client->request('GET', '/jeu');

        self::assertResponseIsSuccessful();

        return $this->wheelData()['csrfToken'];
    }

    private function wheelData(): array
    {
        $json = $this->client->getCrawler()->filter('#roue-data')->text();

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    private function spin(string $token, string $participantUuid): array
    {
        $this->client->request('POST', '/jeu/tourner', ['participant' => $participantUuid], [], ['HTTP_X-CSRF-Token' => $token]);

        return $this->json();
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function createPrize(
        string $name,
        int $weight,
        ?int $stock = null,
        PrizeType $type = PrizeType::CONSOLATION,
        bool $active = true,
    ): Prize {
        $prize = (new Prize($name, $type, $weight))
            ->setRemainingStock($stock)
            ->setActive($active);

        $this->entityManager->persist($prize);
        $this->entityManager->flush();

        return $prize;
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    /**
     * L'alias RandomNumberGeneratorInterface est résolu vers ce service
     * concret dès la compilation du conteneur : c'est donc son identifiant
     * qu'il faut remplacer pour que le pile ou face soit réellement figé,
     * pour SpinService comme pour WeightedPrizeSelector qui le partagent.
     */
    private function forceWinningCoinFlip(): void
    {
        TestRandomNumberGenerator::forceValue(1);
    }

    private function forceLosingCoinFlip(): void
    {
        TestRandomNumberGenerator::forceValue(10);
    }

    private function stockOf(string $prizeName): ?int
    {
        $stock = $this->entityManager->getConnection()->fetchOne(
            'SELECT remaining_stock FROM prize WHERE name = :name',
            ['name' => $prizeName],
        );

        return null === $stock ? null : (int) $stock;
    }
}
