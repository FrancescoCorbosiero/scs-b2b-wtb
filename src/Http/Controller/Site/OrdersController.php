<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Http\Req;
use App\Http\Responder;
use App\Orders\OrderPresenter;
use App\Orders\OrderRepository;
use App\Orders\ProformaPdf;
use App\Orders\TrackingPresenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /account/ordini: stato, tracking e pro-forma PDF degli ordini confermati. Solo gli ordini del cliente. */
final class OrdersController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly OrderRepository $orders,
        private readonly ProformaPdf $pdf,
        private readonly TrackingPresenter $tracking,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $customer = Req::requireCustomer($request);
        $orders = array_map(
            static fn (array $o): array => OrderPresenter::forCustomer($o, []),
            $this->orders->forUser((int) $customer['id']),
        );

        return $this->responder->render($response, 'account/orders.twig', ['orders' => $orders]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $order = $this->own($request, (int) $args['id']);

        return $this->responder->render($response, 'account/order.twig', [
            'order' => OrderPresenter::forCustomer($order, $this->orders->lines((int) $order['id'])),
            'tracking' => $this->tracking->forOrder((int) $order['id']),
        ]);
    }

    /** @param array<string, string> $args */
    public function proforma(Request $request, Response $response, array $args): Response
    {
        $order = $this->own($request, (int) $args['id']);
        if ($order['receipt_number'] === null || $order['status'] === 'pending') {
            throw new HttpNotFoundException($request);
        }

        return $this->responder->download($response, $this->pdf->render($order, $this->orders->lines((int) $order['id'])), ProformaPdf::filename($order), 'application/pdf');
    }

    /** @return array<string, mixed> */
    private function own(Request $request, int $id): array
    {
        $customer = Req::requireCustomer($request);
        $order = $this->orders->find($id);
        if ($order === null || (int) $order['user_id'] !== (int) $customer['id']) {
            throw new HttpNotFoundException($request);
        }

        return $order;
    }
}
