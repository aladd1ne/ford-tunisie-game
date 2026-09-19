<?php

declare(strict_types=1);

namespace App\Exception\Game;

/**
 * Plus aucun lot n'est disponible : tous les lots sont inactifs, non pondérés
 * ou en rupture de stock.
 */
final class NoPrizeAvailableException extends GameException
{
    public function __construct()
    {
        parent::__construct("Aucun lot n'est disponible pour le moment. Merci de réessayer plus tard.");
    }
}
