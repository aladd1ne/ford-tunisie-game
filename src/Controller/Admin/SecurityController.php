<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Connexion / déconnexion du back-office.
 *
 * L'authentification elle-même (vérification des identifiants) est gérée
 * par le pare-feu Symfony (form_login, voir security.yaml) : ce contrôleur
 * se contente d'afficher le formulaire et de relayer la dernière erreur.
 */
class SecurityController extends AbstractController
{
    #[Route('/admin/connexion', name: 'app_admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        return $this->render('admin/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * N'est jamais exécutée : la déconnexion est interceptée par le pare-feu
     * (security.yaml > firewalls.main.logout) avant d'atteindre ce contrôleur.
     */
    #[Route('/admin/deconnexion', name: 'app_admin_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode ne doit jamais être exécutée.');
    }
}
