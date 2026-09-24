<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\ParticipantCrudController;
use App\Entity\Participant;
use App\Entity\Spin;
use App\Entity\User;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * L'équipe (accueil comme super administrateur) télécharge la liste des
 * inscriptions au format Excel depuis le back-office.
 */
final class ParticipantExportTest extends WebTestCase
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

    /**
     * @dataProvider staffRoles
     */
    public function testStaffDownloadsTheRegistrationsAsXlsx(string $role): void
    {
        $this->login($role);
        $this->createParticipant('marc.dupont@exemple.fr', 'Marc', '06 12 34 56 78');

        $crawler = $this->client->request('GET', $this->indexUrl());
        self::assertCount(1, $crawler->filter('a.action-'.ParticipantCrudController::EXPORT_ACTION));

        $rows = $this->download($crawler->filter('a.action-'.ParticipantCrudController::EXPORT_ACTION)->attr('href'));

        self::assertSame(['ID', 'Prénom', 'Nom', 'Société ou agence', 'Adresse e-mail', 'Téléphone', 'A joué', 'Cadeau obtenu', 'Inscrit le', 'Autorisé à jouer le'], $rows[0]);
        self::assertCount(2, $rows);
        self::assertSame(['Marc', 'Dupont', 'Agence Nord', 'marc.dupont@exemple.fr', '06 12 34 56 78', 'Non'], \array_slice($rows[1], 1, 6));
        self::assertInstanceOf(\DateTimeInterface::class, $rows[1][8]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function staffRoles(): iterable
    {
        yield 'accueil' => ['ROLE_INSCRIPTION'];
        yield 'super administrateur' => ['ROLE_SUPER_ADMIN'];
    }

    public function testTheExportKeepsTheListFilters(): void
    {
        $this->login('ROLE_INSCRIPTION');

        $player = $this->createParticipant('marc.dupont@exemple.fr', 'Marc');
        $player->authorizePlay(new \DateTimeImmutable());
        $this->entityManager->persist(new Spin($player, null, new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->createParticipant('claire.martin@exemple.fr', 'Claire');

        $crawler = $this->client->request('GET', $this->indexUrl(['filters' => ['played' => '1']]));
        $rows = $this->download($crawler->filter('a.action-'.ParticipantCrudController::EXPORT_ACTION)->attr('href'));

        self::assertCount(2, $rows);
        self::assertSame('marc.dupont@exemple.fr', $rows[1][4]);
        self::assertSame('Oui', $rows[1][6]);
    }

    /**
     * @return list<list<mixed>>
     */
    private function download(string $url): array
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('attachment; filename=inscriptions-roue-ford-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $response = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);

        // deleteFileAfterSend : le fichier est supprimé une fois envoyé, on
        // relit donc une copie du contenu reçu.
        $path = tempnam(sys_get_temp_dir(), 'export_test_');
        file_put_contents($path, $this->client->getInternalResponse()->getContent());

        $reader = new Reader();
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $reader->close();
        unlink($path);

        return $rows;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function indexUrl(array $parameters = []): string
    {
        return '/admin?'.http_build_query([
            'crudControllerFqcn' => ParticipantCrudController::class,
            'crudAction' => 'index',
        ] + $parameters);
    }

    private function login(string $role): void
    {
        $user = (new User(strtolower($role).'@exemple.fr'))->setRoles([$role]);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
    }

    private function createParticipant(string $email, string $firstName, ?string $phone = null): Participant
    {
        $participant = new Participant($firstName, 'Dupont', 'Agence Nord', $email, $phone);

        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant;
    }
}
