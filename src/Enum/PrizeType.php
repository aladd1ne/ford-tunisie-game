<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'un lot de « La Roue Ford ».
 */
enum PrizeType: string
{
    case MAIN = 'main';
    case CONSOLATION = 'consolation';

    public function label(): string
    {
        return match ($this) {
            self::MAIN => 'Lot principal',
            self::CONSOLATION => 'Lot de consolation',
        };
    }
}
