<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Prize;
use App\Enum\PrizeType;
use App\Service\Game\SpinResultPresenter;
use App\Tests\Support\ResetsDatabase;
use App\Tests\Support\TestRandomNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parcours complet : accueil → inscription → confirmation → roue → résultat.
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
            'Inscrivez-vous, faites tourner la roue et tentez de gagner un cadeau ! À vous de jouer !',
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

    public function testConfirmationGreetsTheParticipantByFirstName(): void
    {
        $this->register();

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inscription confirmée !');
        self::assertSelectorTextContains(
            '.panel__lead--strong',
            'Merci Marc. Vous pouvez maintenant faire tourner La Roue Ford et découvrir votre résultat.',
        );
        self::assertSame('Faire tourner la roue', trim($crawler->filter('.panel a.btn')->text()));
        self::assertSame('/jeu', $crawler->filter('.panel a.btn')->attr('href'));
    }

    public function testWheelRequiresARegistration(): void
    {
        $this->client->request('GET', '/jeu');

        self::assertResponseRedirects('/inscription');
    }

    public function testConfirmationRequiresARegistration(): void
    {
        $this->client->request('GET', '/inscription/confirmation');

        self::assertResponseRedirects('/inscription');
    }

    public function testSpinIsRefusedWithoutARegistration(): void
    {
        $this->client->request('POST', '/jeu/tourner');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('fail', $this->json()['status']);
    }

    public function testSpinIsRefusedWithoutACsrfToken(): void
    {
        $this->createPrize('Casquette Ford', 10);
        $this->register();
        $this->client->request('POST', '/jeu/tourner');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countRows('spin'));
    }

    public function testCompleteFlowAwardsAPrizeAndRendersTheResult(): void
    {
        $this->createPrize('Ford Puma un week-end', 10, 5, PrizeType::MAIN);
        $this->register();

        $token = $this->openWheelAndReadSpinToken();
        $payload = $this->spin($token);

        self::assertResponseIsSuccessful();
        self::assertSame('success', $payload['status']);
        self::assertSame('Félicitations, Marc !', $payload['data']['title']);
        self::assertSame('Vous avez gagné : Ford Puma un week-end', $payload['data']['detail']);
        self::assertSame('Merci d’avoir participé à La Roue Ford !', $payload['data']['thanks']);
        self::assertSame(1, $this->countRows('spin'));

        // Retour sur la page : le résultat est rendu par le serveur.
        $crawler = $this->client->request('GET', '/jeu');

        self::assertSelectorTextContains('#roue-resultat-titre', 'Félicitations, Marc !');
        self::assertSelectorTextContains('#roue-resultat-detail', 'Vous avez gagné : Ford Puma un week-end');
        self::assertSelectorTextContains('#roue-resultat-merci', 'Merci d’avoir participé à La Roue Ford !');
        self::assertSame('Nouvelle partie', trim($crawler->filter('.result__form button')->text()));
        self::assertNotNull($crawler->filter('#roue-bouton')->attr('disabled'), 'La roue ne doit plus être jouable.');
    }

    public function testConsolationPrizeShowsTheOopsMessageInsteadOfCongrats(): void
    {
        $this->createPrize('Porte-clés Ford', 10, null, PrizeType::CONSOLATION);
        $this->register();

        $payload = $this->spin($this->openWheelAndReadSpinToken());

        self::assertSame('Oops', $payload['data']['badge']);
        self::assertSame(SpinResultPresenter::CONSOLATION_TITLE, $payload['data']['title']);
        self::assertFalse($payload['data']['isMainPrize']);
        self::assertSame('Vous avez gagné : Porte-clés Ford', $payload['data']['detail']);

        $crawler = $this->client->request('GET', '/jeu');

        self::assertSelectorTextContains('#roue-resultat-titre', SpinResultPresenter::CONSOLATION_TITLE);
        self::assertSame('Nouvelle partie', trim($crawler->filter('.result__form button')->text()));
    }

    public function testRepeatedSpinRequestsReturnTheSameResult(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $this->register();

        $token = $this->openWheelAndReadSpinToken();

        $first = $this->spin($token);
        $second = $this->spin($token);
        $third = $this->spin($token);

        self::assertSame($first['data']['spinUuid'], $second['data']['spinUuid']);
        self::assertSame($first['data']['spinUuid'], $third['data']['spinUuid']);
        self::assertSame(1, $this->countRows('spin'), 'Un double-clic ne doit produire qu\'un seul tirage.');
        self::assertSame(4, $this->stockOf('Casquette Ford'), 'Le stock ne doit être décrémenté qu\'une fois.');
    }

    public function testClientCannotForceAPrize(): void
    {
        // Le lot principal est présent mais non tirable (poids 0).
        $unreachable = $this->createPrize('Ford Puma un week-end', 0, 5, PrizeType::MAIN);
        $this->createPrize('Porte-clés Ford', 10, null, PrizeType::CONSOLATION);
        $this->register();

        $token = $this->openWheelAndReadSpinToken();

        $this->client->request(
            'POST',
            '/jeu/tourner',
            ['prizeUuid' => (string) $unreachable->getUuid(), 'prize' => 'Ford Puma un week-end'],
            [],
            ['HTTP_X-CSRF-Token' => $token],
        );

        $payload = $this->json();

        self::assertSame('success', $payload['status']);
        self::assertSame('Porte-clés Ford', $payload['data']['prizeName'], 'Le client ne doit pas pouvoir imposer un lot.');
        self::assertSame(5, $this->stockOf('Ford Puma un week-end'));
    }

    /**
     * Même un pile ou face gagnant devient une case perdu si la dotation est
     * épuisée : la roue reste jouable, elle ne renvoie plus d'erreur bloquante.
     */
    public function testSpinBecomesALossWhenNoPrizeIsAvailable(): void
    {
        $this->createPrize('Épuisé', 10, 0);
        $this->register();

        $token = $this->openWheelAndReadSpinToken();
        $payload = $this->spin($token);

        self::assertResponseIsSuccessful();
        self::assertSame('success', $payload['status']);
        self::assertNull($payload['data']['prizeUuid']);
        self::assertSame('Oops', $payload['data']['badge']);
        self::assertSame(1, $this->countRows('spin'));
    }

    public function testNewGameStartsOverWithoutTouchingThePreviousSpin(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $this->register();

        $token = $this->openWheelAndReadSpinToken();
        $firstSpin = $this->spin($token)['data']['spinUuid'];

        $crawler = $this->client->request('GET', '/jeu');
        $this->client->submit($crawler->filter('.result__form')->form());

        self::assertResponseRedirects('/');

        // La session est vide : impossible de rejouer le tirage précédent.
        $this->client->request('GET', '/jeu');
        self::assertResponseRedirects('/inscription');

        $this->client->request('POST', '/jeu/tourner', [], [], ['HTTP_X-CSRF-Token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Une nouvelle inscription rejoue depuis le début, sans altérer le tirage précédent.
        $this->register('claire.martin@exemple.fr', 'Claire');
        $payload = $this->spin($this->openWheelAndReadSpinToken());

        self::assertNotSame($firstSpin, $payload['data']['spinUuid']);
        self::assertSame(2, $this->countRows('spin'));
        self::assertSame(3, $this->stockOf('Casquette Ford'));
    }

    public function testWheelExposesAsManyLossSegmentsAsPrizes(): void
    {
        $this->createPrize('Casquette Ford', 10, 5);
        $this->createPrize('Mug Ford', 10, 5);
        $this->createPrize('Lot retiré', 10, 5, PrizeType::CONSOLATION, false);
        $this->register();

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
        self::assertFalse($data['alreadyPlayed']);
    }

    public function testLosingSpinShowsTheOopsMessageWithNoPrizeAndConsumesNoStock(): void
    {
        $prize = $this->createPrize('Casquette Ford', 10, 5);
        $this->register();
        $this->forceLosingCoinFlip();

        $token = $this->openWheelAndReadSpinToken();
        $payload = $this->spin($token);

        self::assertResponseIsSuccessful();
        self::assertSame('success', $payload['status']);
        self::assertNull($payload['data']['prizeUuid']);
        self::assertNull($payload['data']['prizeName']);
        self::assertFalse($payload['data']['isMainPrize']);
        self::assertSame('Oops', $payload['data']['badge']);
        self::assertSame(SpinResultPresenter::CONSOLATION_TITLE, $payload['data']['title']);
        self::assertNull($payload['data']['detail']);
        self::assertSame(1, $this->countRows('spin'));
        self::assertSame(5, $this->stockOf('Casquette Ford'), 'Une case perdu ne doit consommer aucun stock.');

        // Retour sur la page : le résultat perdant est rendu par le serveur.
        $crawler = $this->client->request('GET', '/jeu');

        self::assertSelectorTextContains('#roue-resultat-titre', SpinResultPresenter::CONSOLATION_TITLE);
        self::assertSame('Nouvelle partie', trim($crawler->filter('.result__form button')->text()));
        self::assertNotNull($crawler->filter('#roue-bouton')->attr('disabled'), 'La roue ne doit plus être jouable.');
    }

    /* ------------------------------------------------------------------ */

    private function submitRegistration(array $values): Crawler
    {
        $crawler = $this->client->request('GET', '/inscription');
        $form = $crawler->filter('form.form')->form();

        return $this->client->submit($form, $values);
    }

    private function register(string $email = 'marc.dupont@exemple.fr', string $firstName = 'Marc'): void
    {
        $this->submitRegistration([
            'registration[firstName]' => $firstName,
            'registration[lastName]' => 'Dupont',
            'registration[company]' => 'Agence Nord',
            'registration[email]' => $email,
            'registration[phone]' => '+33 6 12 34 56 78',
        ]);

        self::assertResponseRedirects('/inscription/confirmation');
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

    private function spin(string $token): array
    {
        $this->client->request('POST', '/jeu/tourner', [], [], ['HTTP_X-CSRF-Token' => $token]);

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
        TestRandomNumberGenerator::forceValue(2);
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
