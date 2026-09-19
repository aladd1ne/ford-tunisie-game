<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * Implémentation par défaut, adossée au générateur cryptographique de PHP.
 */
final class CryptoRandomNumberGenerator implements RandomNumberGeneratorInterface
{
    public function nextInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
