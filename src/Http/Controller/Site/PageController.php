<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Http\Responder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Pagine pubbliche di presentazione (indicizzabili, senza prodotti né prezzi). */
final class PageController
{
    public function __construct(private readonly Responder $responder)
    {
    }

    public function home(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'site/home.twig');
    }

    public function health(Request $request, Response $response): Response
    {
        $response->getBody()->write('ok');

        return $response->withHeader('Content-Type', 'text/plain');
    }
}
