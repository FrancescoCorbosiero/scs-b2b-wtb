<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Responder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Dashboard admin: riepiloghi e avvisi (completata nelle milestone successive). */
final class DashboardController
{
    public function __construct(private readonly Responder $responder)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'admin/dashboard.twig', []);
    }
}
