<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Game\RandomNumberGeneratorInterface;

/**
 * Générateur constant : renvoie toujours la même valeur, quelles que soient
 * les bornes demandées. Sert à figer le pile ou face de SpinService dans les
 * tests qui ne portent pas sur le tirage lui-même.
 */
final class FixedRandomNumberGenerator implements RandomNumberGeneratorInterface
{
    public function __construct(private readonly int $value)
    {
    }

    public function nextInt(int $min, int $max): int
    {
        return $this->value;
    }
}
