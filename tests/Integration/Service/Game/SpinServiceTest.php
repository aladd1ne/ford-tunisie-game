<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Game;

use App\Dto\RegistrationDto;
use App\Entity\Participant;
use App\Entity\Prize;
use App\Entity\Spin;
use App\Enum\PrizeType;
use App\Exception\Game\NoPrizeAvailableException;
use App\Exception\Game\NotAuthorizedException;
use App\Repository\PrizeRepository;
use App\Repository\SpinRepository;
use App\Service\Game\PrizeSelectorInterface;
use App\Service\Game\RandomNumberGeneratorInterface;
use App\Service\Game\SpinContext;
use App\Service\Game\SpinResultPresenter;
use App\Service\Game\SpinService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\FixedRandomNumberGenerator;
use Doctrine\DBAL\Connection;
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
        self::assertTrue($spin->isWin());
        self::assertSame(4, $this->refreshStock($prize));
    }

    /**
     * Un seul tirage par participant : spin() est idempotent et ne consomme
     * jamais de stock supplémentaire.
     */
    public function testSpinningAgainAfterAWinReturnsTheSameResultAndConsumesStockOnce(): void
    {
        $prize = $this->createPrize('Mug Ford', 10, 5);
        $participant = $this->createParticipant();
        $service = $this->spinService();

        $first = $service->spin($participant);
        $second = $service->spin($participant);
        $third = $service->spin($participant);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame($first->getId(), $third->getId());
        self::assertSame(4, $this->refreshStock($prize), 'Un rejeu après un gain ne doit jamais reconsommer du stock.');
        self::assertSame(1, $this->countSpins());
    }

    /**
     * Une case « perdu » est tout aussi définitive : la tentative suivante
     * renvoie le même tirage perdant, même si elle aurait été gagnante.
     */
    public function testSpinningAgainAfterALossReturnsTheSameLosingSpin(): void
    {
        $prize = $this->createPrize('Casquette Ford', 10, 5);
        $participant = $this->createParticipant();

        $first = $this->spinService(null, null, new FixedRandomNumberGenerator(10))->spin($participant);
        $second = $this->spinService(null, null, new FixedRandomNumberGenerator(1))->spin($participant);

        self::assertFalse($first->isWin());
        self::assertSame($first->getId(), $second->getId());
        self::assertFalse($second->isWin());
        self::assertSame(1, $this->countSpins());
        self::assertSame(5, $this->refreshStock($prize));
    }

    public function testAParticipantWhoWasNotAuthorizedCannotSpin(): void
    {
        $prize = $this->createPrize('Casquette Ford', 10, 5);
        $participant = $this->createParticipant(authorized: false);

        try {
            $this->spinService()->spin($participant);
            self::fail('Un participant non autorisé ne doit pas pouvoir jouer.');
        } catch (NotAuthorizedException) {
        }

        self::assertSame(0, $this->countSpins());
        self::assertSame(5, $this->refreshStock($prize));
    }

    public function testDatabaseAllowsSeveralSpinsForTheSameParticipant(): void
    {
        $prize = $this->createPrize('Porte-clés Ford', 10);
        $participant = $this->createParticipant();

        // Contournement volontaire du service : la base accepte plusieurs
        // tirages pour un même participant (historique antérieur à la règle
        // « un seul tirage ») ; c'est SpinService qui applique la règle.
        $this->entityManager->persist(new Spin($participant, null, new \DateTimeImmutable()));
        $this->entityManager->persist(new Spin($participant, $prize, new \DateTimeImmutable()));
        $this->entityManager->flush();

        self::assertSame(2, $this->countSpins());
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

    /**
     * Même un tirage « gagnant » au pile ou face devient une case perdu si la
     * dotation est épuisée entre-temps : la roue continue de tourner
     * normalement, ce n'est plus une erreur bloquante.
     */
    public function testStockNeverGoesNegative(): void
    {
        $prize = $this->createPrize('Stock unitaire', 10, 1);
        $service = $this->spinService();

        $service->spin($this->createParticipant('a@exemple.fr'));

        self::assertSame(0, $this->refreshStock($prize));

        $second = $service->spin($this->createParticipant('b@exemple.fr'));

        self::assertNull($second->getPrize());
        self::assertFalse($second->isWin());
        self::assertSame(0, $this->refreshStock($prize));
        self::assertSame(2, $this->countSpins());
    }

    public function testSpinBecomesALossWhenEverythingIsInactiveOrEmpty(): void
    {
        $this->createPrize('Inactif', 100, null, PrizeType::CONSOLATION, false);
        $this->createPrize('Épuisé', 100, 0);
        $this->createPrize('Poids nul', 0, null);

        $spin = $this->spinService()->spin($this->createParticipant());

        self::assertNull($spin->getPrize());
        self::assertNull($spin->getPrizeName());
        self::assertNull($spin->getPrizeType());
        self::assertFalse($spin->isWin());
    }

    public function testNoStockIsConsumedWhenTheSpinBecomesALoss(): void
    {
        $prize = $this->createPrize('Épuisé', 100, 0);

        $spin = $this->spinService()->spin($this->createParticipant());

        self::assertNull($spin->getPrize());
        self::assertSame(0, $this->refreshStock($prize));
        self::assertSame(1, $this->countSpins());
    }

    public function testALosingCoinFlipAwardsNoPrizeEvenWhenStockIsAvailable(): void
    {
        $prize = $this->createPrize('Casquette Ford', 10, 5);

        $spin = $this->spinService(null, null, new FixedRandomNumberGenerator(10))
            ->spin($this->createParticipant());

        self::assertNull($spin->getPrize());
        self::assertNull($spin->getPrizeName());
        self::assertFalse($spin->isWin());
        self::assertSame(5, $this->refreshStock($prize), 'Une case perdu ne doit consommer aucun stock.');
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

    public function testMainPrizeTypeIsRecordedOnTheSpin(): void
    {
        $this->createPrize('Ford Puma un week-end', 10, 1, PrizeType::MAIN);

        $spin = $this->spinService()->spin($this->createParticipant());

        self::assertSame(PrizeType::MAIN, $spin->getPrizeType());
    }

    public function testConsolationPrizeTypeIsRecordedOnTheSpin(): void
    {
        $this->createPrize('Mug Ford', 10, 1, PrizeType::CONSOLATION);

        $spin = $this->spinService()->spin($this->createParticipant());

        self::assertSame(PrizeType::CONSOLATION, $spin->getPrizeType());
    }

    /**
     * spin.prize_id est en ON DELETE SET NULL (voir Spin) : supprimer un lot
     * depuis le back-office ne doit jamais être bloqué par les tirages
     * passés, et l'historique affiché au participant doit rester correct
     * puisque prizeName/prizeType sont recopiés indépendamment de la relation.
     */
    public function testDeletingAPrizeSetsThePastSpinsPrizeToNullWithoutLosingTheHistory(): void
    {
        $prize = $this->createPrize('Enceinte connectée Ford', 10, 5, PrizeType::MAIN);
        $spin = $this->spinService()->spin($this->createParticipant());

        self::assertSame($prize->getId(), $spin->getPrize()->getId());

        $this->entityManager->remove($prize);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = self::getContainer()->get(SpinRepository::class)->find($spin->getId());

        self::assertNull($reloaded->getPrize(), 'Le lot supprimé ne doit plus être référencé.');
        self::assertSame('Enceinte connectée Ford', $reloaded->getPrizeName(), 'Le nom du lot doit rester traçable.');
        self::assertSame(PrizeType::MAIN, $reloaded->getPrizeType());
        self::assertTrue($reloaded->isWin(), 'Le tirage reste gagnant même si le lot a été supprimé depuis.');

        $result = self::getContainer()->get(SpinResultPresenter::class)->present($reloaded);

        self::assertTrue($result['isWin']);
        self::assertSame('Félicitations, Marc !', $result['title']);
        self::assertSame('Vous avez gagné : Enceinte connectée Ford', $result['detail']);
        self::assertNull($result['prizeUuid'], 'Plus de lot vivant à référencer.');
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

    /**
     * Le pile ou face du gain est forcé côté gagnant par défaut : la plupart
     * de ces tests portent sur le stock ou la sélection pondérée, pas sur la
     * probabilité de gain elle-même (couverte séparément).
     */
    private function spinService(
        ?PrizeSelectorInterface $selector = null,
        ?MockClock $clock = null,
        ?RandomNumberGeneratorInterface $randomNumberGenerator = null,
    ): SpinService {
        $container = self::getContainer();

        return new SpinService(
            $this->entityManager,
            $container->get(PrizeRepository::class),
            $container->get(SpinRepository::class),
            $selector ?? $container->get(PrizeSelectorInterface::class),
            $randomNumberGenerator ?? new FixedRandomNumberGenerator(1),
            $clock ?? new MockClock(),
            new NullLogger(),
        );
    }

    private function createParticipant(string $email = 'marc.dupont@exemple.fr', bool $authorized = true): Participant
    {
        $dto = new RegistrationDto();
        $dto->firstName = 'Marc';
        $dto->lastName = 'Dupont';
        $dto->company = 'Agence Nord';
        $dto->email = $email;

        $participant = $dto->toParticipant();

        if ($authorized) {
            $participant->authorizePlay(new \DateTimeImmutable());
        }

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
