<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\PrizeCrudController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le super administrateur crée des comptes « accueil » qui n'ont accès
 * qu'aux inscriptions du back-office.
 */
final class InscriptionAccountTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->resetDatabase($this->entityManager);
    }

    public function testSuperAdminCreatesAnInscriptionAccount(): void
    {
        $this->client->loginUser($this->createUser('patron@exemple.fr', 'ROLE_SUPER_ADMIN'));

        $crawler = $this->client->request('GET', $this->crudUrl(UserCrudController::class, Action::NEW));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#new-User-form')->form();
        $prefix = $form->getFormNode()->getAttribute('name');
        $this->client->submit($form, [
            $prefix.'[email]' => ' Hotesse@Exemple.fr ',
            $prefix.'[plainPassword][first]' => 'motdepasse',
            $prefix.'[plainPassword][second]' => 'motdepasse',
        ]);

        self::assertResponseRedirects();

        $account = self::getContainer()->get(UserRepository::class)->findOneByEmail('hotesse@exemple.fr');
        self::assertNotNull($account);
        self::assertTrue($account->isInscriptionOnly());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($account, 'motdepasse'));
    }

    public function testAdminAccountsAreNeitherListedNorEditable(): void
    {
        $superAdmin = $this->createUser('patron@exemple.fr', 'ROLE_SUPER_ADMIN');
        $this->createUser('hotesse@exemple.fr', User::ROLE_INSCRIPTION);
        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request('GET', $this->crudUrl(UserCrudController::class, Action::INDEX));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table tbody tr[data-id]'));
        self::assertSelectorTextContains('table tbody', 'hotesse@exemple.fr');

        $this->client->request('GET', $this->crudUrl(UserCrudController::class, Action::EDIT, $superAdmin->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testInscriptionAccountOnlyReachesInscriptions(): void
    {
        $this->client->loginUser($this->createUser('hotesse@exemple.fr', User::ROLE_INSCRIPTION));

        $this->client->request('GET', '/admin');
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inscriptions à La Roue Ford');
        self::assertStringNotContainsString('Lots', $crawler->filter('#main-menu')->text());
        self::assertStringNotContainsString('Comptes accueil', $crawler->filter('#main-menu')->text());

        $this->client->request('GET', $this->crudUrl(PrizeCrudController::class, Action::INDEX));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', $this->crudUrl(UserCrudController::class, Action::INDEX));
        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardUsesTheLightThemeByDefault(): void
    {
        $this->client->loginUser($this->createUser('hotesse@exemple.fr', User::ROLE_INSCRIPTION));

        $this->client->request('GET', '/admin');
        $crawler = $this->client->followRedirect();

        self::assertSame('light', $crawler->filter('body')->attr('data-ea-default-color-scheme'));
    }

    private function createUser(string $email, string $role): User
    {
        $user = (new User($email))->setRoles([$role]);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'motdepasse'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function crudUrl(string $crudController, string $action, ?int $entityId = null): string
    {
        return '/admin?'.http_build_query(array_filter([
            'crudControllerFqcn' => $crudController,
            'crudAction' => $action,
            'entityId' => $entityId,
        ]));
    }
}
