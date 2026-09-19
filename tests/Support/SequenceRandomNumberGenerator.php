<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Game\RandomNumberGeneratorInterface;
use LogicException;

/**
 * Générateur déterministe : renvoie les tickets fournis, dans l'ordre.
 *
 * Permet de piloter exactement le résultat d'un tirage pondéré dans les tests.
 */
final class SequenceRandomNumberGenerator implements RandomNumberGeneratorInterface
{
    /**
     * @var list<int>
     */
    private array $sequence;

    private int $cursor = 0;

    public int $lastMin = 0;

    public int $lastMax = 0;

    public function __construct(int ...$sequence)
    {
        $this->sequence = array_values($sequence);
    }

    public function nextInt(int $min, int $max): int
    {
        $this->lastMin = $min;
        $this->lastMax = $max;

        if (!isset($this->sequence[$this->cursor])) {
            throw new LogicException('La séquence de tirage est épuisée.');
        }

        return $this->sequence[$this->cursor++];
    }
}
