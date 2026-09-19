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
     *     winning: bool,
     *     title: string,
     *     detail: string,
     *     thanks: string
     * }
     */
    public function present(Spin $spin): array
    {
        $participant = $spin->getParticipant();
        $winning = $spin->isWinning();

        return [
            'spinUuid' => (string) $spin->getUuid(),
            'prizeUuid' => (string) $spin->getPrize()->getUuid(),
            'prizeName' => $spin->getPrizeName(),
            'prizeDescription' => $spin->getPrize()->getDescription(),
            'winning' => $winning,
            'title' => $winning
                ? sprintf('Félicitations, %s !', $participant->getFirstName())
                : 'Oops, vous n’avez pas gagné le lot principal, mais une petite surprise vous attend !',
            'detail' => $winning
                ? sprintf('Vous avez gagné : %s', $spin->getPrizeName())
                : sprintf('Votre surprise : %s', $spin->getPrizeName()),
            'thanks' => self::THANK_YOU_MESSAGE,
        ];
    }
}
