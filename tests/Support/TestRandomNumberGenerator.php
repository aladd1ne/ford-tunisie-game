<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Game\RandomNumberGeneratorInterface;

/**
 * Remplace CryptoRandomNumberGenerator dans le conteneur de test (voir
 * config/services.yaml, bloc when@test).
 *
 * Piloté par un état statique plutôt que par injection de constructeur : les
 * tests fonctionnels passent par de vraies requêtes HTTP, donc par des
 * services déjà câblés par le conteneur (SpinService, WeightedPrizeSelector).
 * Remplacer le service après coup via le conteneur ne les atteindrait pas
 * (les instances déjà construites gardent leur dépendance d'origine) ; un
 * état statique, lui, est visible immédiatement par toute instance déjà
 * construite.
 *
 * Sans valeur forcée, le comportement est identique au générateur
 * cryptographique réel.
 */
final class TestRandomNumberGenerator implements RandomNumberGeneratorInterface
{
    private static ?int $forcedValue = null;

    public static function forceValue(?int $value): void
    {
        self::$forcedValue = $value;
    }

    public static function reset(): void
    {
        self::$forcedValue = null;
    }

    public function nextInt(int $min, int $max): int
    {
        return self::$forcedValue ?? random_int($min, $max);
    }
}
