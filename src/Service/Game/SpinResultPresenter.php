<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Spin;
use App\Enum\PrizeType;

/**
 * Met en forme le résultat d'un tirage pour l'affichage.
 *
 * Partagé par la réponse JSON (animation de la roue) et par le rendu Twig
 * (retour sur la page après un rafraîchissement), afin que les deux chemins
 * affichent exactement le même message.
 *
 * La moitié des tirages ne désignent aucun lot (voir SpinService) : parmi les
 * tirages gagnants, seul le lot principal est présenté comme une victoire
 * franche, tout autre résultat (lot de consolation ou case « perdu »)
 * affiche le message « Oops » pour ne pas laisser croire que le participant
 * a remporté le gros lot.
 *
 * Le résultat s'appuie sur $spin->getPrizeType()/getPrizeName(), recopiés au
 * moment du tirage, plutôt que sur l'entité Prize elle-même : un lot peut
 * être supprimé ensuite (ON DELETE SET NULL, voir Spin) sans que l'historique
 * affiché au participant n'en soit affecté. Seuls prizeUuid et
 * prizeDescription, non recopiés, redeviennent alors indisponibles.
 */
final class SpinResultPresenter
{
    public const THANK_YOU_MESSAGE = 'Merci d’avoir participé à La Roue Ford !';
    public const MAIN_PRIZE_BADGE = 'Gagné';
    public const CONSOLATION_BADGE = 'Oops';
    public const CONSOLATION_TITLE = 'Oops, vous n’avez pas gagné le lot principal, mais une petite surprise vous attend !';

    /**
     * @return array{
     *     spinUuid: string,
     *     prizeUuid: string|null,
     *     prizeName: string|null,
     *     prizeDescription: string|null,
     *     isMainPrize: bool,
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
        $isMainPrize = PrizeType::MAIN === $spin->getPrizeType();

        return [
            'spinUuid' => (string) $spin->getUuid(),
            'prizeUuid' => null !== $prize ? (string) $prize->getUuid() : null,
            'prizeName' => $prizeName,
            'prizeDescription' => $prize?->getDescription(),
            'isMainPrize' => $isMainPrize,
            'badge' => $isMainPrize ? self::MAIN_PRIZE_BADGE : self::CONSOLATION_BADGE,
            'title' => $isMainPrize
                ? sprintf('Félicitations, %s !', $participant->getFirstName())
                : self::CONSOLATION_TITLE,
            'detail' => null !== $prizeName ? sprintf('Vous avez gagné : %s', $prizeName) : null,
            'thanks' => self::THANK_YOU_MESSAGE,
        ];
    }
}
