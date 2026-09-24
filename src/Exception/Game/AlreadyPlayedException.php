<?php

declare(strict_types=1);

namespace App\Exception\Game;

/**
 * Le participant a déjà fait tourner la roue : chacun ne joue qu'une fois.
 */
final class AlreadyPlayedException extends GameException
{
    public function __construct()
    {
        parent::__construct('Ce participant a déjà joué.');
    }
}
