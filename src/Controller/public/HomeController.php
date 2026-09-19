<?php

declare(strict_types=1);

namespace App\Controller\public;

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Page d'accueil de « La Roue Ford ».
 */
class HomeController extends BaseController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('game/landing.html.twig');
    }
}
