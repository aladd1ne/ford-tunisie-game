<?php

declare(strict_types=1);

namespace App\Controller\public;

use App\Controller\BaseController;
use App\Entity\Participant;
use App\Entity\Prize;
use App\Exception\Game\GameException;
use App\Http\JSend;
use App\Repository\ParticipantRepository;
use App\Repository\PrizeRepository;
use App\Service\Game\PlayAuthorization;
use App\Service\Game\SpinContext;
use App\Service\Game\SpinResultPresenter;
use App\Service\Game\SpinService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * La roue et le tirage.
 *
 * La roue est un écran public, sans formulaire d'inscription : elle attend
 * qu'un participant soit autorisé à jouer depuis le back-office (voir
 * PlayAuthorization), puis le laisse faire tourner la roue une seule fois.
 *
 * Le contrôleur reste volontairement mince : il vérifie le jeton CSRF et
 * l'autorisation du participant, délègue la décision à SpinService, puis
 * renvoie le résultat. Aucun identifiant de lot n'est accepté en entrée : le
 * client ne peut en aucun cas influencer le résultat.
 */
class GameController extends BaseController
{
    public const SPIN_CSRF_TOKEN_ID = 'roue_ford_spin';

    /**
     * Couleur des cases « perdu », volontairement neutre pour se distinguer
     * des couleurs de marque utilisées par les lots.
     */
    private const LOSS_SEGMENT_COLOR = '#DCE3F0';
    private const LOSS_SEGMENT_LABEL = 'À bientôt';

    public function __construct(
        private readonly PlayAuthorization $playAuthorization,
        private readonly SpinResultPresenter $resultPresenter,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/jeu', name: 'app_game', methods: ['GET'])]
    public function wheel(PrizeRepository $prizeRepository): Response
    {
        $prizes = $prizeRepository->findForWheel();

        return $this->render('game/wheel.html.twig', [
            'player' => $this->presentPlayer($this->playAuthorization->currentPlayer()),
            'prizes' => $prizes,
            'segments' => $this->buildSegments($prizes),
        ]);
    }

    /**
     * Joueur attendu sur la roue, interrogé régulièrement par l'écran en
     * attente d'un participant autorisé depuis le back-office.
     */
    #[Route('/jeu/joueur', name: 'app_game_player', methods: ['GET'])]
    public function player(): JsonResponse
    {
        return JSend::success('Joueur attendu.', [
            'player' => $this->presentPlayer($this->playAuthorization->currentPlayer()),
        ]);
    }

    /**
     * Détermine le résultat côté serveur. Le JavaScript ne fait qu'animer la
     * roue vers le lot renvoyé ici.
     *
     * Le participant désigné doit avoir été autorisé depuis le back-office et
     * ne pas avoir déjà joué. Le jeton CSRF est régénéré à chaque réponse
     * réussie et renvoyé au client (nextSpinToken) : la roue peut accueillir
     * le joueur suivant sans recharger la page, et un rejeu réseau ou un
     * retour arrière du navigateur ne peut pas soumettre deux fois la même
     * requête.
     */
    #[Route('/jeu/tourner', name: 'app_game_spin', methods: ['POST'])]
    public function spin(Request $request, SpinService $spinService, ParticipantRepository $participantRepository): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::SPIN_CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return JSend::fail('Session expirée. Merci de recharger la page.', [], 0, Response::HTTP_FORBIDDEN);
        }

        $uuid = (string) $request->request->get('participant');
        $participant = '' === $uuid ? null : $participantRepository->findOneByUuid($uuid);

        if (null === $participant || !$participant->canPlay()) {
            return JSend::fail("Ce participant n'est pas autorisé à jouer. Merci de vous présenter à l'accueil.", [], 0, Response::HTTP_FORBIDDEN);
        }

        $context = new SpinContext(
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        try {
            $spin = $spinService->spin($participant, $context);
        } catch (GameException $exception) {
            return JSend::fail($exception->getMessage(), [], 0, Response::HTTP_CONFLICT);
        }

        $nextSpinToken = $this->csrfTokenManager->refreshToken(self::SPIN_CSRF_TOKEN_ID)->getValue();

        return JSend::success('Résultat du tirage.', [
            ...$this->resultPresenter->present($spin),
            'nextSpinToken' => $nextSpinToken,
        ]);
    }

    /**
     * @return array{uuid: string, firstName: string}|null
     */
    private function presentPlayer(?Participant $participant): ?array
    {
        if (null === $participant) {
            return null;
        }

        return [
            'uuid' => (string) $participant->getUuid(),
            'firstName' => $participant->getFirstName(),
        ];
    }

    /**
     * Construit les cases de la roue : une case par lot, plus autant de cases
     * « perdu » que de lots, en alternance. Le résultat réel (70 % de gain,
     * voir SpinService) est déterminé côté serveur avant l'animation : la
     * roue anime simplement jusqu'à la case correspondante.
     *
     * @param Prize[] $prizes
     *
     * @return list<array{type: string, uuid: string, name: string, color: string|null}>
     */
    private function buildSegments(array $prizes): array
    {
        $segments = [];

        foreach (array_values($prizes) as $index => $prize) {
            $segments[] = [
                'type' => 'prize',
                'uuid' => (string) $prize->getUuid(),
                'name' => $prize->getName(),
                'color' => $prize->getColor(),
            ];
            $segments[] = [
                'type' => 'loss',
                'uuid' => sprintf('loss-%d', $index),
                'name' => self::LOSS_SEGMENT_LABEL,
                'color' => self::LOSS_SEGMENT_COLOR,
            ];
        }

        return $segments;
    }
}
