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
 *
 * Le résultat n'a que deux issues (voir SpinService) : tout lot gagné,
 * principal ou de consolation, est présenté comme une victoire franche ;
 * seule une case « perdu » (aucun lot désigné) affiche le message « À bientôt ».
 *
 * Le résultat s'appuie sur $spin->isWin()/getPrizeName(), recopiés au
 * moment du tirage, plutôt que sur l'entité Prize elle-même : un lot peut
 * être supprimé ensuite (ON DELETE SET NULL, voir Spin) sans que l'historique
 * affiché au participant n'en soit affecté. Seuls prizeUuid et
 * prizeDescription, non recopiés, redeviennent alors indisponibles.
 */
final class SpinResultPresenter
{
    public const THANK_YOU_MESSAGE = 'Merci d’avoir participé à La Roue Ford !';
    public const WIN_BADGE = 'Gagné';
    public const LOSS_BADGE = 'À bientôt';
    public const LOSS_TITLE = 'Pas de lot cette fois-ci, merci d’avoir participé et à bientôt !';

    /**
     * @return array{
     *     spinUuid: string,
     *     prizeUuid: string|null,
     *     prizeName: string|null,
     *     prizeDescription: string|null,
     *     prizeRemainingStock: int|null,
     *     isWin: bool,
     *     badge: string,
     *     title: string,
     *     detail: string|null,
     *     thanks: string
     * }
     */
    public function present(Spin $spin): array
    {
        $participant = $spin->getParticipant();
        $prize = $spin->getPrize();
        $prizeName = $spin->getPrizeName();
        $isWin = $spin->isWin();

        return [
            'spinUuid' => (string) $spin->getUuid(),
            'prizeUuid' => null !== $prize ? (string) $prize->getUuid() : null,
            'prizeName' => $prizeName,
            'prizeDescription' => $prize?->getDescription(),
            // Stock restant après ce tirage (null : illimité ou lot supprimé),
            // pour mettre à jour la légende sans recharger la page.
            'prizeRemainingStock' => $prize?->getRemainingStock(),
            'isWin' => $isWin,
            'badge' => $isWin ? self::WIN_BADGE : self::LOSS_BADGE,
            'title' => $isWin
                ? sprintf('Félicitations, %s !', $participant->getFirstName())
                : self::LOSS_TITLE,
            'detail' => null !== $prizeName ? sprintf('Vous avez gagné : %s', $prizeName) : null,
            'thanks' => self::THANK_YOU_MESSAGE,
        ];
    }
}
