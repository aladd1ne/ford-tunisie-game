<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Participant;
use App\Entity\Prize;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office de « La Roue Ford ».
 *
 * Permet de gérer les lots (nom, poids, stock, activation) et, à l'entrée de
 * l'événement, de vérifier les inscriptions et d'autoriser les participants
 * à jouer (voir ParticipantCrudController), sans jamais toucher à la logique de jeu (SpinService,
 * WeightedPrizeSelector) : celle-ci lit toujours les lots directement en
 * base.
 */
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->redirect($this->adminUrlGenerator->setController(ParticipantCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('La Roue Ford')
            ->setLocales(['fr']);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::section('Jeu');
        yield MenuItem::linkToCrud('Inscriptions', 'fa fa-users', Participant::class);
        yield MenuItem::linkToCrud('Lots', 'fa fa-gift', Prize::class);
        yield MenuItem::section('Accueil des visiteurs');
        // Pages publiques, ouvertes hors du back-office.
        yield MenuItem::linkToUrl('Inscrire un visiteur', 'fa fa-user-plus', $this->generateUrl('app_registration'));
        yield MenuItem::linkToUrl('Ouvrir la roue', 'fa fa-circle-notch', $this->generateUrl('app_game'))
            ->setLinkTarget('_blank');
        yield MenuItem::section();
        yield MenuItem::linkToUrl('Voir le site', 'fa fa-arrow-up-right-from-square', '/');
    }
}
