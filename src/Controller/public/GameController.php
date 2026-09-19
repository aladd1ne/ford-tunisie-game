<?php

declare(strict_types=1);

namespace App\Controller\public;

use App\Controller\BaseController;
use App\Entity\Prize;
use App\Exception\Game\GameException;
use App\Http\JSend;
use App\Repository\PrizeRepository;
use App\Service\Game\GameSession;
use App\Service\Game\SpinContext;
use App\Service\Game\SpinResultPresenter;
use App\Service\Game\SpinService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * La roue et le tirage.
 *
 * Le contrôleur reste volontairement mince : il vérifie l'inscription et le
 * jeton CSRF, délègue la décision à SpinService, puis renvoie le résultat.
 * Aucun identifiant de lot n'est accepté en entrée : le client ne peut en
 * aucun cas influencer le résultat.
 */
class GameController extends BaseController
{
    public const SPIN_CSRF_TOKEN_ID = 'roue_ford_spin';
    public const RESTART_CSRF_TOKEN_ID = 'roue_ford_restart';

    public function __construct(
        private readonly GameSession $gameSession,
        private readonly SpinResultPresenter $resultPresenter,
    ) {
    }

    #[Route('/jeu', name: 'app_game', methods: ['GET'])]
    public function wheel(PrizeRepository $prizeRepository): Response
    {
        $participant = $this->gameSession->getParticipant();

        if (null === $participant) {
            $this->addFlash('warning', 'Inscrivez-vous pour faire tourner La Roue Ford.');

            return $this->redirectToRoute('app_registration');
        }

        $spin = $participant->getSpin();

        return $this->render('game/wheel.html.twig', [
            'participant' => $participant,
            'segments' => $this->buildSegments($prizeRepository->findForWheel()),
            // Déjà joué : le résultat est rendu directement par le serveur.
            'result' => null === $spin ? null : $this->resultPresenter->present($spin),
        ]);
    }

    /**
     * Détermine le résultat côté serveur. Le JavaScript ne fait qu'animer la
     * roue vers le lot renvoyé ici.
     */
    #[Route('/jeu/tourner', name: 'app_game_spin', methods: ['POST'])]
    public function spin(Request $request, SpinService $spinService): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::SPIN_CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return JSend::fail('Session expirée. Merci de recharger la page.', [], 0, Response::HTTP_FORBIDDEN);
        }

        $participant = $this->gameSession->getParticipant();

        if (null === $participant) {
            return JSend::fail('Vous devez vous inscrire avant de faire tourner la roue.', [], 0, Response::HTTP_FORBIDDEN);
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

        return JSend::success('Résultat du tirage.', $this->resultPresenter->present($spin));
    }

    /**
     * « Nouvelle partie » : la session repart à zéro. Le tirage précédent
     * reste enregistré en base et ne peut être ni rejoué ni modifié.
     */
    #[Route('/nouvelle-partie', name: 'app_game_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::RESTART_CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_home');
        }

        $this->gameSession->clear();

        return $this->redirectToRoute('app_home');
    }

    /**
     * @param Prize[] $prizes
     *
     * @return list<array{uuid: string, name: string, color: string|null, winning: bool}>
     */
    private function buildSegments(array $prizes): array
    {
        return array_map(static fn (Prize $prize): array => [
            'uuid' => (string) $prize->getUuid(),
            'name' => $prize->getName(),
            'color' => $prize->getColor(),
            'winning' => $prize->isWinning(),
        ], $prizes);
    }
}
