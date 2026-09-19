<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Spin;

/**
 * Met en forme le résultat d'un tirage pour l'affichage.
 *
 * Partagé par la réponse JSON (animation de la roue) et par le rendu Twig
 * (retour sur la page après un rafraîchissement), afin que les deux chemins
 * affichent exactement le même message.
 */
final class SpinResultPresenter
{
    public const THANK_YOU_MESSAGE = 'Merci d’avoir participé à La Roue Ford !';

    /**
     * @return array{
     *     spinUuid: string,
     *     prizeUuid: string,
     *     prizeName: string,
     *     prizeDescription: string|null,
     *     title: string,
     *     detail: string,
     *     thanks: string
     * }
     */
    public function present(Spin $spin): array
    {
        $participant = $spin->getParticipant();

        return [
            'spinUuid' => (string) $spin->getUuid(),
            'prizeUuid' => (string) $spin->getPrize()->getUuid(),
            'prizeName' => $spin->getPrizeName(),
            'prizeDescription' => $spin->getPrize()->getDescription(),
            'title' => sprintf('Félicitations, %s !', $participant->getFirstName()),
            'detail' => sprintf('Vous avez gagné : %s', $spin->getPrizeName()),
            'thanks' => self::THANK_YOU_MESSAGE,
        ];
    }
}
