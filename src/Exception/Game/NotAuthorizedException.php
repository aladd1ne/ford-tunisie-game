<?php

declare(strict_types=1);

namespace App\Exception\Game;

/**
 * Le participant n'a pas (encore) été autorisé à jouer depuis le back-office.
 */
final class NotAuthorizedException extends GameException
{
    public function __construct()
    {
        parent::__construct("Ce participant n'est pas autorisé à jouer. Merci de vous présenter à l'accueil.");
    }
}
