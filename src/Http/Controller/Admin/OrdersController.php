<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Req;
use App\Http\Responder;
use App\Orders\OrderRepository;
use App\Orders\OrderService;
use App\Orders\ProformaPdf;
use App\Orders\TrackingPresenter;
use App\Pricing\MarginResolver;
use App\Settings\SettingsService;
use App\Supplier\Wtb\WtbConfig;
use App\Support\Db;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * /admin/ordini: elenco con filtri; dettaglio con righe (vendita, costo, margine), totali, spedizione incassata
 * vs costo del fornitore, preventivo, azioni (conferma, riallinea, annulla) e ordine al fornitore.
 */
final class OrdersController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly OrderRepository $orders,
        private readonly OrderService $service,
        private readonly ProformaPdf $pdf,
        private readonly TrackingPresenter $tracking,
        private readonly SettingsService $settings,
        private readonly WtbConfig $wtb,
        private readonly Db $db,
        private readonly Translator $t,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $status = Req::query($request, 'stato');
        $status = in_array($status, ['pending', 'confirmed', 'shipped', 'cancelled'], true) ? $status : '';
        $mode = Req::query($request, 'modalita', $this->wtb->mode);
        $mode = in_array($mode, ['fixture', 'test', 'live', 'tutte'], true) ? $mode : $this->wtb->mode;
        $q = Req::query($request, 'q');

        return $this->responder->render($response, 'admin/orders/index.twig', [
            'orders' => $this->orders->search($status, $q, $mode === 'tutte' ? '' : $mode),
            'status' => $status,
            'mode' => $mode,
            'q' => $q,
        ]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->find((int) $args['id']) ?? throw new HttpNotFoundException($request);
        $vat = $this->settings->int('tax.vat_rate_it');
        $lines = array_map(static function (array $l) use ($vat): array {
            $sale = (int) $l['line_total_cents'];
            $cost = (int) $l['qty_immediate'] * (int) $l['unit_cost_immediate_cents'] + (int) $l['qty_extended'] * (int) $l['unit_cost_extended_cents'];

            return $l + ['cost_total' => $cost, 'margin' => $sale - $cost, 'net' => MarginResolver::netMarginEstimate($sale, $cost, (string) $l['vat_regime'], $vat)];
        }, $this->orders->lines((int) $order['id']));
        $goods = (int) $order['subtotal_margin_cents'] + (int) $order['subtotal_ordinary_cents'];
        $stock = [];
        foreach ($lines as $l) {
            $stock[(int) $l['id']] = $this->db->int(
                'SELECT COALESCE(SUM(o.available), 0) FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.wtb_variant_id = :w AND o.vat_regime = :r',
                ['w' => $l['wtb_variant_id'], 'r' => $l['vat_regime']],
            );
        }

        return $this->responder->render($response, 'admin/orders/show.twig', [
            'order' => $order,
            'lines' => $lines,
            'stock' => $stock,
            'goods_margin' => $goods - (int) $order['expected_cost_cents'],
            'shipping_diff' => $order['wtb_shipping_cost_cents'] === null ? null : (int) $order['shipping_cents'] - (int) $order['wtb_shipping_cost_cents'],
            'quote' => $order['quote_snapshot'] === null ? null : json_decode((string) $order['quote_snapshot'], true),
            'requires_vies' => $this->service->requiresVies($order),
            'can_realign' => in_array($order['status'], ['pending', 'confirmed'], true) && !$this->service->hasLiveSupplierOrder((int) $order['id']),
            'tracking' => $this->tracking->forOrder((int) $order['id']),
            'other_mode' => $order['mode'] !== $this->wtb->mode,
            'supplier_orders' => $this->supplierOrders((int) $order['id']),
            'portal_url' => $this->wtb->portalUrl(),
        ]);
    }

    /** @param array<string, string> $args */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $result = $this->service->confirm($id, Req::adminName($request), Req::str($request, 'vies') === '1');
        $session = Req::session($request);
        if ($result['status'] === 'ok') {
            $session->flash(($result['mailed'] ?? false) ? 'success' : 'warning', $this->t->get(($result['mailed'] ?? false) ? 'admin.orders.confirmed' : 'admin.orders.confirmed_not_mailed', ['receipt' => $result['receipt'] ?? '']));
            foreach ($result['messages'] ?? [] as $message) {
                $session->flash($message['type'], $message['text']);
            }
        } else {
            $session->flash('error', $this->t->get('admin.orders.errors.' . $result['status']));
        }

        return $this->responder->redirect($response, '/admin/ordini/' . $id);
    }

    /** @param array<string, string> $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $ok = $this->service->cancel($id, Req::adminName($request));
        $session = Req::session($request);
        $session->flash($ok ? 'success' : 'error', $this->t->get($ok ? 'admin.orders.cancelled' : 'admin.orders.errors.not_cancellable'));
        if ($ok && $this->service->hasLiveSupplierOrder($id)) {
            $session->flash('warning', $this->t->get('admin.orders.cancel_supplier_reminder', ['url' => $this->wtb->portalUrl()]));
        }

        return $this->responder->redirect($response, '/admin/ordini/' . $id);
    }

    /** @param array<string, string> $args */
    public function realign(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $raw = Req::input($request)['qty'] ?? [];
        $quantities = [];
        foreach (is_array($raw) ? $raw : [] as $lineId => $qty) {
            if (is_scalar($qty) && preg_match('/^\d{1,4}$/', (string) $qty) === 1) {
                $quantities[(int) $lineId] = (int) $qty;
            }
        }
        $result = $this->service->realign($id, $quantities, Req::adminName($request), Req::str($request, 'notify') === '1');
        $session = Req::session($request);
        $session->flash($result['status'] === 'ok' ? 'success' : 'error', $this->t->get(
            $result['status'] === 'ok' ? (($result['mailed'] ?? false) ? 'admin.orders.realigned_mailed' : 'admin.orders.realigned') : 'admin.orders.errors.realign_' . $result['status'],
            ['n' => $result['changed'] ?? 0],
        ));

        return $this->responder->redirect($response, '/admin/ordini/' . $id);
    }

    /** @param array<string, string> $args */
    public function proforma(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->find((int) $args['id']) ?? throw new HttpNotFoundException($request);
        if ($order['receipt_number'] === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->responder->download($response, $this->pdf->render($order, $this->orders->lines((int) $order['id'])), ProformaPdf::filename($order), 'application/pdf');
    }

    /** @return list<array<string, mixed>> */
    private function supplierOrders(int $orderId): array
    {
        try {
            return $this->db->all('SELECT * FROM supplier_orders WHERE order_id = :o ORDER BY id DESC', ['o' => $orderId]);
        } catch (\PDOException) {
            return [];
        }
    }
}
