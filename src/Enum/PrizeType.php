<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'un lot de « La Roue Ford ».
 *
 * Le type détermine le message affiché au participant à l'issue du tirage :
 * un lot principal déclenche un message de félicitations, un lot de
 * consolation le message « petite surprise ».
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

    /**
     * Seul un lot principal est considéré comme un gain « gagnant ».
     */
    public function isWinning(): bool
    {
        return self::MAIN === $this;
    }
}
