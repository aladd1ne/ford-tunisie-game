<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Prize;
use App\Exception\Game\NoPrizeAvailableException;
use App\Exception\ShouldNotHappenException;

/**
 * Tirage aléatoire pondéré.
 *
 * Chaque lot reçoit un intervalle de tickets proportionnel à son poids sur
 * un segment [1, somme des poids] ; un ticket est ensuite tiré au sort et le
 * lot propriétaire de l'intervalle l'emporte. Un lot de poids 50 a donc cinq
 * fois plus de chances de sortir qu'un lot de poids 10.
 *
 * Les lots inactifs, de poids nul ou en rupture de stock sont écartés avant
 * le tirage.
 */
final class WeightedPrizeSelector implements PrizeSelectorInterface
{
    public function __construct(
        private readonly RandomNumberGeneratorInterface $randomNumberGenerator,
    ) {
    }

    public function select(array $prizes): Prize
    {
        $candidates = array_values(array_filter($prizes, static fn (Prize $prize): bool => $prize->isSelectable()));

        $totalWeight = 0;
        foreach ($candidates as $candidate) {
            $totalWeight += $candidate->getWeight();
        }

        if ($totalWeight <= 0) {
            throw new NoPrizeAvailableException();
        }

        $ticket = $this->randomNumberGenerator->nextInt(1, $totalWeight);

        $cursor = 0;
        foreach ($candidates as $candidate) {
            $cursor += $candidate->getWeight();

            if ($ticket <= $cursor) {
                return $candidate;
            }
        }

        // Inatteignable : $ticket est borné par la somme des poids.
        throw new ShouldNotHappenException('Le tirage pondéré n\'a désigné aucun lot.');
    }
}
