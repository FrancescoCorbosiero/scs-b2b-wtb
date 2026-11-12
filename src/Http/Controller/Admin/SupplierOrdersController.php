<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Req;
use App\Http\Responder;
use App\Orders\PaymentInstructions;
use App\Supplier\Exception\SupplierException;
use App\Supplier\Exception\SupplierNotFoundException;
use App\Supplier\SupplierOrders;
use App\Support\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /admin/ordini-fornitore: elenco dall'API del fornitore collegato ai nostri ordini, dettaglio letto dal vivo (docs/07). */
final class SupplierOrdersController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly SupplierOrders $supplier,
        private readonly Db $db,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, min(500, (int) Req::query($request, 'pagina', '1')));
        $orders = [];
        $hasMore = false;
        $error = null;
        try {
            $result = $this->supplier->listOrders($page);
            $orders = $result['orders'];
            $hasMore = $result['has_more'];
        } catch (SupplierException $e) {
            $error = $e->getMessage();
        }
        $links = $this->links(array_map(static fn ($o): string => $o->reference, $orders));

        return $this->responder->render($response, 'admin/supplier_orders/index.twig', [
            'orders' => array_map(static fn ($o): array => [
                'data' => $o,
                'pairs' => array_sum(array_map(static fn (array $l): int => $l['quantity'], $o->lines)),
                'local' => $links[$o->reference] ?? null,
            ], $orders),
            'page' => $page,
            'has_more' => $hasMore,
            'error' => $error,
            'mode' => $this->supplier->mode(),
        ]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $order = $this->supplier->getOrder((int) $args['id']);
        } catch (SupplierNotFoundException) {
            throw new HttpNotFoundException($request);
        } catch (SupplierException $e) {
            return $this->responder->render($response, 'admin/supplier_orders/show.twig', ['order' => null, 'error' => $e->getMessage(), 'id' => (int) $args['id']]);
        }

        return $this->responder->render($response, 'admin/supplier_orders/show.twig', [
            'order' => $order,
            'error' => null,
            'id' => (int) $args['id'],
            'instructions' => $order->paymentInstructions === null ? [] : PaymentInstructions::fromArray($order->paymentInstructions),
            'local' => $this->links([$order->reference])[$order->reference] ?? null,
        ]);
    }

    /**
     * Ordini al fornitore registrati da noi, per reference.
     *
     * @param list<string> $references
     * @return array<string, array<string, mixed>>
     */
    private function links(array $references): array
    {
        $references = array_values(array_filter(array_unique($references), static fn (string $r): bool => $r !== ''));
        if ($references === []) {
            return [];
        }
        $params = [];
        foreach ($references as $i => $reference) {
            $params['r' . $i] = $reference;
        }
        $rows = $this->db->all(
            'SELECT s.id, s.order_id, s.reference, s.status, o.company FROM supplier_orders s JOIN orders o ON o.id = s.order_id
             WHERE s.reference IN (:' . implode(', :', array_keys($params)) . ')',
            $params,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['reference']] = $row;
        }

        return $out;
    }
}
