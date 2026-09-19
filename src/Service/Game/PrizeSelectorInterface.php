<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Prize;
use App\Exception\Game\NoPrizeAvailableException;

/**
 * Stratégie de sélection du lot gagnant.
 *
 * Le résultat est déterminé exclusivement côté serveur : aucune donnée
 * provenant du client n'intervient dans la sélection.
 */
interface PrizeSelectorInterface
{
    /**
     * @param Prize[] $prizes lots candidats
     *
     * @throws NoPrizeAvailableException si aucun candidat n'est tirable
     */
    public function select(array $prizes): Prize;
}
