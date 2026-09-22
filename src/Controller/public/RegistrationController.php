<?php

declare(strict_types=1);

namespace App\Controller\public;

use App\Controller\BaseController;
use App\Dto\RegistrationDto;
use App\Form\RegistrationType;
use App\Service\Game\GameSession;
use App\Service\Game\ParticipantRegistrar;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Inscription au jeu, préalable obligatoire à toute partie.
 */
class RegistrationController extends BaseController
{
    public function __construct(
        private readonly GameSession $gameSession,
    ) {
    }

    #[Route('/inscription', name: 'app_registration', methods: ['GET', 'POST'])]
    public function register(Request $request, ParticipantRegistrar $registrar): Response
    {
        $form = $this->createForm(RegistrationType::class, new RegistrationDto());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RegistrationDto $registration */
            $registration = $form->getData();

            $participant = $registrar->register($registration);
            $this->gameSession->start($participant);

            return $this->redirectToRoute('app_game');
        }

        return $this->render('game/registration.html.twig', [
            'form' => $form->createView(),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
