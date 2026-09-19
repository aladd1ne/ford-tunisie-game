<?php

declare(strict_types=1);

namespace App\Exception\Game;

use RuntimeException;

/**
 * Exception métier du jeu « La Roue Ford ».
 *
 * Le message est destiné à être affiché tel quel au participant : il est
 * donc rédigé en français.
 */
abstract class GameException extends RuntimeException
{
}
