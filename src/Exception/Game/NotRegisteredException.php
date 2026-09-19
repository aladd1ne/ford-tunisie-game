<?php

declare(strict_types=1);

namespace App\Exception\Game;

/**
 * Aucune inscription valide n'est attachée à la session courante.
 */
final class NotRegisteredException extends GameException
{
    public function __construct()
    {
        parent::__construct('Vous devez vous inscrire avant de faire tourner la roue.');
    }
}
