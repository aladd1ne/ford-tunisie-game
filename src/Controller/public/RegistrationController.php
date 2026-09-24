<?php

declare(strict_types=1);

namespace App\Controller\public;

use App\Controller\BaseController;
use App\Dto\RegistrationDto;
use App\Form\RegistrationType;
use App\Service\Registration\ParticipantRegistrar;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Inscription à l'événement : formulaire puis confirmation.
 *
 * L'inscription ne donne pas directement accès à la roue : le participant se
 * présente ensuite à l'accueil, où l'équipe l'autorise à jouer depuis le
 * back-office.
 */
class RegistrationController extends BaseController
{
    /**
     * Clé du message flash portant le prénom du participant tout juste
     * inscrit, affiché une seule fois sur la page de confirmation.
     */
    private const CONFIRMATION_FLASH = 'registration_confirmed';

    #[Route('/inscription', name: 'app_registration', methods: ['GET', 'POST'])]
    public function register(Request $request, ParticipantRegistrar $registrar): Response
    {
        $form = $this->createForm(RegistrationType::class, new RegistrationDto());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RegistrationDto $registration */
            $registration = $form->getData();

            $participant = $registrar->register($registration);
            $this->addFlash(self::CONFIRMATION_FLASH, $participant->getFirstName());

            return $this->redirectToRoute('app_registration_confirmation');
        }

        return $this->render('registration/form.html.twig', [
            'form' => $form->createView(),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/inscription/confirmation', name: 'app_registration_confirmation', methods: ['GET'])]
    public function confirmation(Request $request): Response
    {
        $firstNames = $request->getSession()->getFlashBag()->get(self::CONFIRMATION_FLASH);

        if ([] === $firstNames) {
            return $this->redirectToRoute('app_registration');
        }

        return $this->render('registration/confirmation.html.twig', [
            'firstName' => end($firstNames),
        ]);
    }
}
