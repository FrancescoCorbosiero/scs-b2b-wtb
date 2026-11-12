<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Admin\Alerts;
use App\Admin\DashboardCards;
use App\Http\Responder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Dashboard admin: avvisi e riepiloghi (docs/07). */
final class DashboardController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Alerts $alerts,
        private readonly DashboardCards $cards,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'admin/dashboard.twig', [
            'alerts' => $this->alerts->all(),
            'cards' => $this->cards->all(),
            'to_pay' => $this->cards->toPay(),
        ]);
    }
}
