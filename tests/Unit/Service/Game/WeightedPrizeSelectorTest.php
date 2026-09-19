<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Game;

use App\Entity\Prize;
use App\Enum\PrizeType;
use App\Exception\Game\NoPrizeAvailableException;
use App\Service\Game\CryptoRandomNumberGenerator;
use App\Service\Game\WeightedPrizeSelector;
use App\Tests\Support\PrizeFactory;
use App\Tests\Support\SequenceRandomNumberGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\Game\WeightedPrizeSelector
 */
final class WeightedPrizeSelectorTest extends TestCase
{
    public function testTicketIsDrawnOverTheSumOfWeights(): void
    {
        $generator = new SequenceRandomNumberGenerator(1);
        $selector = new WeightedPrizeSelector($generator);

        $selector->select([
            PrizeFactory::create('A', 10),
            PrizeFactory::create('B', 50),
        ]);

        self::assertSame(1, $generator->lastMin);
        self::assertSame(60, $generator->lastMax, 'Le ticket doit être tiré sur la somme des poids.');
    }

    /**
     * Chaque lot doit posséder exactement autant de tickets que son poids :
     * c'est la définition même d'un tirage proportionnel.
     */
    public function testEachPrizeOwnsExactlyAsManyTicketsAsItsWeight(): void
    {
        $prizes = [
            PrizeFactory::create('Poids 10', 10),
            PrizeFactory::create('Poids 50', 50),
            PrizeFactory::create('Poids 40', 40),
        ];

        $wins = ['Poids 10' => 0, 'Poids 50' => 0, 'Poids 40' => 0];

        for ($ticket = 1; $ticket <= 100; ++$ticket) {
            $selector = new WeightedPrizeSelector(new SequenceRandomNumberGenerator($ticket));
            ++$wins[$selector->select($prizes)->getName()];
        }

        self::assertSame(['Poids 10' => 10, 'Poids 50' => 50, 'Poids 40' => 40], $wins);
    }

    public function testHeavierPrizeIsFiveTimesMoreLikelyThanLighterOne(): void
    {
        $prizes = [
            PrizeFactory::create('Léger', 10),
            PrizeFactory::create('Lourd', 50),
        ];

        $light = 0;
        $heavy = 0;

        for ($ticket = 1; $ticket <= 60; ++$ticket) {
            $selector = new WeightedPrizeSelector(new SequenceRandomNumberGenerator($ticket));

            if ('Léger' === $selector->select($prizes)->getName()) {
                ++$light;
            } else {
                ++$heavy;
            }
        }

        self::assertSame(10, $light);
        self::assertSame(50, $heavy);
        self::assertEqualsWithDelta(5.0, $heavy / $light, 0.0001);
    }

    public function testBoundaryTicketsSelectTheExpectedPrize(): void
    {
        $prizes = [
            PrizeFactory::create('A', 10),
            PrizeFactory::create('B', 50),
        ];

        self::assertSame('A', $this->selectWithTicket($prizes, 1)->getName());
        self::assertSame('A', $this->selectWithTicket($prizes, 10)->getName());
        self::assertSame('B', $this->selectWithTicket($prizes, 11)->getName());
        self::assertSame('B', $this->selectWithTicket($prizes, 60)->getName());
    }

    public function testExhaustedPrizeIsNeverSelected(): void
    {
        $prizes = [
            PrizeFactory::create('Épuisé', 1000, 0),
            PrizeFactory::create('Disponible', 1, 5),
        ];

        // Quel que soit le ticket, seul le lot disponible peut sortir.
        for ($ticket = 1; $ticket <= 1; ++$ticket) {
            self::assertSame('Disponible', $this->selectWithTicket($prizes, $ticket)->getName());
        }

        $selector = new WeightedPrizeSelector($generator = new SequenceRandomNumberGenerator(1));
        $selector->select($prizes);

        self::assertSame(1, $generator->lastMax, 'Le poids du lot épuisé ne doit pas entrer dans la somme.');
    }

    public function testInactivePrizeIsNeverSelected(): void
    {
        $prizes = [
            PrizeFactory::create('Inactif', 1000, null, false),
            PrizeFactory::create('Actif', 3),
        ];

        $selector = new WeightedPrizeSelector($generator = new SequenceRandomNumberGenerator(2));

        self::assertSame('Actif', $selector->select($prizes)->getName());
        self::assertSame(3, $generator->lastMax);
    }

    public function testZeroWeightPrizeIsNeverSelected(): void
    {
        $prizes = [
            PrizeFactory::create('Poids nul', 0),
            PrizeFactory::create('Actif', 7),
        ];

        $selector = new WeightedPrizeSelector(new SequenceRandomNumberGenerator(7));

        self::assertSame('Actif', $selector->select($prizes)->getName());
    }

    public function testUnlimitedStockPrizeStaysSelectable(): void
    {
        $prize = PrizeFactory::create('Illimité', 5, null);

        self::assertNull($prize->getRemainingStock());
        self::assertTrue($prize->isSelectable());

        $selector = new WeightedPrizeSelector(new SequenceRandomNumberGenerator(5));

        self::assertSame('Illimité', $selector->select([$prize])->getName());
    }

    public function testEmptyCandidateListThrows(): void
    {
        $this->expectException(NoPrizeAvailableException::class);

        (new WeightedPrizeSelector(new SequenceRandomNumberGenerator(1)))->select([]);
    }

    public function testAllPrizesExhaustedThrows(): void
    {
        $this->expectException(NoPrizeAvailableException::class);
        $this->expectExceptionMessage("Aucun lot n'est disponible pour le moment.");

        (new WeightedPrizeSelector(new SequenceRandomNumberGenerator(1)))->select([
            PrizeFactory::create('Épuisé', 10, 0),
            PrizeFactory::create('Inactif', 10, null, false),
            PrizeFactory::create('Poids nul', 0),
        ]);
    }

    /**
     * Garde-fou sur l'implémentation réelle : sur un grand nombre de tirages,
     * la répartition doit rester cohérente avec les poids.
     */
    public function testRealGeneratorRoughlyRespectsWeights(): void
    {
        $selector = new WeightedPrizeSelector(new CryptoRandomNumberGenerator());
        $prizes = [
            PrizeFactory::create('Rare', 5),
            PrizeFactory::create('Fréquent', 95),
        ];

        $rare = 0;
        $draws = 4000;

        for ($i = 0; $i < $draws; ++$i) {
            if ('Rare' === $selector->select($prizes)->getName()) {
                ++$rare;
            }
        }

        $ratio = $rare / $draws;

        self::assertGreaterThan(0.02, $ratio, 'Le lot rare sort trop rarement.');
        self::assertLessThan(0.09, $ratio, 'Le lot rare sort trop souvent.');
    }

    public function testMainPrizeTypeIsPreserved(): void
    {
        $selector = new WeightedPrizeSelector(new SequenceRandomNumberGenerator(1));

        $prize = $selector->select([
            PrizeFactory::create('Lot principal', 1, null, true, PrizeType::MAIN),
        ]);

        self::assertSame(PrizeType::MAIN, $prize->getType());
    }

    /**
     * @param Prize[] $prizes
     */
    private function selectWithTicket(array $prizes, int $ticket): Prize
    {
        return (new WeightedPrizeSelector(new SequenceRandomNumberGenerator($ticket)))->select($prizes);
    }
}
