<?php

declare(strict_types=1);

namespace App\Exception\Game;

/**
 * Le participant a déjà fait tourner la roue.
 *
 * Levée uniquement lorsque la contrainte d'unicité en base intercepte deux
 * tirages réellement simultanés pour un même participant.
 */
final class AlreadySpunException extends GameException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Vous avez déjà fait tourner la roue. Actualisez la page pour revoir votre résultat.', 0, $previous);
    }
}
