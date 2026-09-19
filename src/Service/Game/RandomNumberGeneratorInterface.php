<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * Source d'aléa du tirage.
 *
 * Isolée derrière une interface pour pouvoir rendre le tirage déterministe
 * dans les tests sans altérer la logique de sélection.
 */
interface RandomNumberGeneratorInterface
{
    /**
     * Renvoie un entier aléatoire compris entre $min et $max (bornes incluses).
     */
    public function nextInt(int $min, int $max): int;
}
