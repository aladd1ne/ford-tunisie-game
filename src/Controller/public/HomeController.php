<?php

declare(strict_types=1);

namespace App\Controller\public;

use App\Controller\BaseController;
use App\Exception\ApiException;
use App\Exception\ShouldNotHappenException;
use App\Http\JSend;
use App\Service\EventService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends BaseController
{
    public function __construct(

    )
    {
    }
    #[Route('/', name: 'api_home', defaults: ['locale' => 'fr',], methods: ['GET'])]
    public function index(): Response
    {
        try {
            return $this->render('base.html.twig');
        } catch (ApiException|ShouldNotHappenException $e) {
            return JSend::error($e->getMessage(), $e->getCode());
        }
    }
}