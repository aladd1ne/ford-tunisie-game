<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Game;

use App\Dto\RegistrationDto;
use App\Entity\Participant;
use App\Entity\Prize;
use App\Entity\Spin;
use App\Enum\PrizeType;
use App\Exception\Game\NoPrizeAvailableException;
use App\Repository\PrizeRepository;
use App\Repository\SpinRepository;
use App\Service\Game\PrizeSelectorInterface;
use App\Service\Game\SpinContext;
use App\Service\Game\SpinService;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * @covers \App\Service\Game\SpinService
 */
final class SpinServiceTest extends DatabaseTestCase
{
    public function testSpinRecordsTheResultAndConsumesOneUnitOfStock(): void
    {
        $prize = $this->createPrize('Casquette Ford', 10, 5);
        $participant = $this->createParticipant();

        $spin = $this->spinService()->spin($participant, new SpinContext('203.0.113.10', 'PHPUnit'));

        self::assertSame($prize->getId(), $spin->getPrize()->getId());
        self::assertSame('Casquette Ford', $spin->getPrizeName());
        self::assertSame(PrizeType::CONSOLATION, $spin->getPrizeType());
        self::assertSame('203.0.113.10', $spin->getIpAddress());
        self::assertSame('PHPUnit', $spin->getUserAgent());
        self::assertNotNull($spin->getUuid());
        self::assertSame(4, $this->refreshStock($prize));
    }

    public function testSpinningTwiceReturnsTheSameResultAndConsumesStockOnce(): void
    {
        $prize = $this->createPrize('Mug Ford', 10, 5);
        $participant = $this->createParticipant();
        $service = $this->spinService();

        $first = $service->spin($participant);
        $second = $service->spin($participant);
        $third = $service->spin($participant);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame($first->getId(), $third->getId());
        self::assertSame(4, $this->refreshStock($prize), 'Un rejeu ne doit jamais reconsommer du stock.');
        self::assertSame(1, $this->countSpins());
    }

    public function testDatabaseRefusesASecondSpinForTheSameParticipant(): void
    {
        $prize = $this->createPrize('Porte-clés Ford', 10);
        $participant = $this->createParticipant();

        $this->spinService()->spin($participant);

        // Contournement volontaire du service : c'est la contrainte d'unicité
        // en base qui doit faire barrage.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->persist(new Spin($participant, $prize, new \DateTimeImmutable()));
        $this->entityManager->flush();
    }

    public function testAnExhaustedPrizeIsNeverAwardedAgain(): void
    {
        $rare = $this->createPrize('Lot unique', 1000, 1, PrizeType::MAIN);
        $common = $this->createPrize('Lot courant', 1, null);
        $service = $this->spinService();

        for ($i = 0; $i < 12; ++$i) {
            $service->spin($this->createParticipant(sprintf('joueur%d@exemple.fr', $i)));
        }

        self::assertSame(0, $this->refreshStock($rare));
        self::assertSame(1, $this->countSpinsForPrize($rare), 'Le lot en stock 1 ne peut être gagné qu\'une fois.');
        self::assertSame(11, $this->countSpinsForPrize($common));
        self::assertSame(12, $this->countSpins());
    }

    public function testStockNeverGoesNegative(): void
    {
        $prize = $this->createPrize('Stock unitaire', 10, 1);
        $service = $this->spinService();

        $service->spin($this->createParticipant('a@exemple.fr'));

        self::assertSame(0, $this->refreshStock($prize));

        try {
            $service->spin($this->createParticipant('b@exemple.fr'));
            self::fail('Un tirage sans lot disponible doit échouer.');
        } catch (NoPrizeAvailableException $exception) {
            self::assertSame("Aucun lot n'est disponible pour le moment. Merci de réessayer plus tard.", $exception->getMessage());
        }

        self::assertSame(0, $this->refreshStock($prize));
        self::assertSame(1, $this->countSpins());
    }

    public function testNoPrizeAvailableWhenEverythingIsInactiveOrEmpty(): void
    {
        $this->createPrize('Inactif', 100, null, PrizeType::CONSOLATION, false);
        $this->createPrize('Épuisé', 100, 0);
        $this->createPrize('Poids nul', 0, null);

        $this->expectException(NoPrizeAvailableException::class);

        $this->spinService()->spin($this->createParticipant());
    }

    public function testNoStockIsConsumedWhenTheSpinFails(): void
    {
        $prize = $this->createPrize('Épuisé', 100, 0);

        try {
            $this->spinService()->spin($this->createParticipant());
        } catch (NoPrizeAvailableException) {
            // attendu
        }

        self::assertSame(0, $this->refreshStock($prize));
        self::assertSame(0, $this->countSpins());
    }

    /**
     * Reproduit une course : le lot est encore disponible au chargement des
     * candidats, mais sa dernière unité est prise juste avant la réservation.
     * Le service doit l'écarter et retirer au sort sur les lots restants.
     */
    public function testServiceDrawsAgainWhenTheSelectedPrizeWasJustExhausted(): void
    {
        $exhausted = $this->createPrize('Épuisé entre-temps', 10, 1);
        $fallback = $this->createPrize('Lot de repli', 10, 5);

        $selector = new class($this->entityManager->getConnection(), $exhausted->getId(), $fallback->getId()) implements PrizeSelectorInterface {
            public int $calls = 0;

            public function __construct(
                private readonly Connection $connection,
                private readonly int $firstId,
                private readonly int $secondId,
            ) {
            }

            public function select(array $prizes): Prize
            {
                ++$this->calls;

                if (1 === $this->calls) {
                    // Un autre joueur emporte la dernière unité entre le
                    // chargement des candidats et la réservation.
                    $this->connection->executeStatement(
                        'UPDATE prize SET remaining_stock = 0 WHERE id = :id',
                        ['id' => $this->firstId],
                    );

                    return $this->findById($prizes, $this->firstId);
                }

                return $this->findById($prizes, $this->secondId);
            }

            /**
             * @param Prize[] $prizes
             */
            private function findById(array $prizes, int $id): Prize
            {
                foreach ($prizes as $prize) {
                    if ($prize->getId() === $id) {
                        return $prize;
                    }
                }

                throw new NoPrizeAvailableException();
            }
        };

        $spin = $this->spinService($selector)->spin($this->createParticipant());

        self::assertSame(2, $selector->calls, 'Le service doit retirer au sort après un stock épuisé.');
        self::assertSame($fallback->getId(), $spin->getPrize()->getId());
        self::assertSame(0, $this->refreshStock($exhausted), 'Le lot épuisé ne doit pas passer en stock négatif.');
        self::assertSame(4, $this->refreshStock($fallback));
    }

    public function testUnlimitedStockPrizeCanBeAwardedRepeatedly(): void
    {
        $prize = $this->createPrize('Bon de réduction', 10, null);
        $service = $this->spinService();

        for ($i = 0; $i < 5; ++$i) {
            $service->spin($this->createParticipant(sprintf('joueur%d@exemple.fr', $i)));
        }

        self::assertNull($this->refreshStock($prize));
        self::assertSame(5, $this->countSpinsForPrize($prize));
    }

    public function testSpunAtComesFromTheClock(): void
    {
        $this->createPrize('Casquette Ford', 10);
        $clock = new MockClock('2026-09-19 14:30:00');

        $spin = $this->spinService(null, $clock)->spin($this->createParticipant());

        self::assertSame('2026-09-19 14:30:00', $spin->getSpunAt()->format('Y-m-d H:i:s'));
    }

    public function testMainPrizeIsFlaggedAsWinning(): void
    {
        $this->createPrize('Ford Puma un week-end', 10, 1, PrizeType::MAIN);

        $spin = $this->spinService()->spin($this->createParticipant());

        self::assertTrue($spin->isWinning());
        self::assertSame(PrizeType::MAIN, $spin->getPrizeType());
    }

    public function testConsolationPrizeIsNotFlaggedAsWinning(): void
    {
        $this->createPrize('Mug Ford', 10, 1, PrizeType::CONSOLATION);

        $spin = $this->spinService()->spin($this->createParticipant());

        self::assertFalse($spin->isWinning());
    }

    public function testDecrementStockReturnsFalseOnceEmpty(): void
    {
        $prize = $this->createPrize('Stock unitaire', 10, 1);
        $repository = self::getContainer()->get(PrizeRepository::class);

        self::assertTrue($repository->decrementStock($prize));
        self::assertFalse($repository->decrementStock($prize));
        self::assertSame(0, $this->refreshStock($prize));
    }

    /* ------------------------------------------------------------------ */

    private function spinService(?PrizeSelectorInterface $selector = null, ?MockClock $clock = null): SpinService
    {
        $container = self::getContainer();

        return new SpinService(
            $this->entityManager,
            $container->get(PrizeRepository::class),
            $container->get(SpinRepository::class),
            $selector ?? $container->get(PrizeSelectorInterface::class),
            $clock ?? new MockClock(),
            new NullLogger(),
        );
    }

    private function createParticipant(string $email = 'marc.dupont@exemple.fr'): Participant
    {
        $dto = new RegistrationDto();
        $dto->firstName = 'Marc';
        $dto->lastName = 'Dupont';
        $dto->company = 'Agence Nord';
        $dto->email = $email;

        $participant = $dto->toParticipant();

        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant;
    }

    private function refreshStock(Prize $prize): ?int
    {
        $stock = $this->entityManager->getConnection()->fetchOne(
            'SELECT remaining_stock FROM prize WHERE id = :id',
            ['id' => $prize->getId()],
        );

        return null === $stock ? null : (int) $stock;
    }

    private function countSpins(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM spin');
    }

    private function countSpinsForPrize(Prize $prize): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM spin WHERE prize_id = :id',
            ['id' => $prize->getId()],
        );
    }
}
