<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Participant;
use App\Entity\Spin;
use App\Entity\User;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * À l'entrée : l'équipe retrouve le visiteur dans le back-office et
 * l'autorise à jouer, ce qui l'amène sur la roue.
 */
final class ParticipantAuthorizationTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->resetDatabase($this->entityManager);

        $admin = (new User('accueil@exemple.fr'))->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $this->client->loginUser($admin);
    }

    public function testStaffFindsARegisteredVisitorAndAuthorizesThemToPlay(): void
    {
        $this->createParticipant('marc.dupont@exemple.fr', 'Marc');
        $this->createParticipant('claire.martin@exemple.fr', 'Claire');

        $crawler = $this->client->request('GET', '/admin');
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inscriptions à La Roue Ford');

        // Recherche par e-mail : seul le visiteur concerné reste listé.
        $crawler = $this->client->submit($crawler->filter('form.form-action-search')->form(), [
            'query' => 'marc.dupont@exemple.fr',
        ]);

        self::assertCount(1, $crawler->filter('table tbody tr[data-id]'));

        $this->client->click($crawler->filter('a.action-authorizePlay')->link());

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Marc Dupont est autorisé(e) à jouer');

        $this->client->request('GET', '/jeu/joueur');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('Marc', $payload['data']['player']['firstName']);
    }

    public function testTheActionIsHiddenOnceTheParticipantHasPlayed(): void
    {
        $participant = $this->createParticipant('marc.dupont@exemple.fr', 'Marc');
        $participant->authorizePlay(new \DateTimeImmutable());
        $this->entityManager->persist(new Spin($participant, null, new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->client->request('GET', '/admin');
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('table tbody tr[data-id]'));
        self::assertCount(0, $crawler->filter('a.action-authorizePlay'));
    }

    private function createParticipant(string $email, string $firstName): Participant
    {
        $participant = new Participant($firstName, 'Dupont', 'Agence Nord', $email);

        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant;
    }
}
