<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Req;
use App\Http\Responder;
use App\Orders\WebhookProcessor;
use App\Supplier\SupplierOrders;
use App\Supplier\SupplierWebhooks;
use App\Support\Db;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * /admin/webhook: eventi ricevuti con esito; "Simula evento" solo in fixture e test: un evento firmato col segreto
 * configurato passa dallo stesso codice dei webhook veri (docs/03, docs/07).
 */
final class WebhooksController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly WebhookProcessor $processor,
        private readonly SupplierWebhooks $webhooks,
        private readonly SupplierOrders $supplier,
        private readonly Db $db,
        private readonly Translator $t,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $outcome = Req::query($request, 'esito');
        $outcome = in_array($outcome, WebhookProcessor::OUTCOMES, true) ? $outcome : '';
        $events = $this->db->all(
            'SELECT e.*, s.reference, s.order_id FROM webhook_events e LEFT JOIN supplier_orders s ON s.id = e.supplier_order_id
             WHERE (:o = \'\' OR e.outcome = :o) ORDER BY e.id DESC LIMIT 100',
            ['o' => $outcome],
        );
        $mode = $this->supplier->mode();

        return $this->responder->render($response, 'admin/webhooks/index.twig', [
            'events' => $events,
            'outcome' => $outcome,
            'outcomes' => WebhookProcessor::OUTCOMES,
            'mode' => $mode,
            'can_simulate' => $mode !== 'live',
            'configured' => $this->webhooks->isConfigured(),
            'event_names' => $this->webhooks->events(),
            'references' => $mode === 'live' ? [] : $this->db->column(
                'SELECT reference FROM supplier_orders WHERE mode = :m ORDER BY id DESC LIMIT 30',
                ['m' => $mode],
            ),
            'last_ping' => $this->db->value("SELECT received_at FROM webhook_events WHERE outcome = 'ping' AND simulated = 0 ORDER BY id DESC LIMIT 1"),
        ]);
    }

    public function simulate(Request $request, Response $response): Response
    {
        if ($this->supplier->mode() === 'live') {
            throw new HttpNotFoundException($request);
        }
        $session = Req::session($request);
        if (!$this->webhooks->isConfigured()) {
            $session->flash('error', $this->t->get('admin.webhooks.no_secret'));

            return $this->responder->redirect($response, '/admin/webhook');
        }
        $event = Req::str($request, 'event');
        $reference = Req::str($request, 'reference');
        if (!in_array($event, $this->webhooks->events(), true) || ($event !== 'ping' && preg_match('/^[A-Za-z0-9._\-]{1,60}$/', $reference) !== 1)) {
            $session->flash('error', $this->t->get('admin.webhooks.invalid'));

            return $this->responder->redirect($response, '/admin/webhook');
        }
        $order = null;
        if ($event !== 'ping') {
            $stored = $this->db->value('SELECT response_payload FROM supplier_orders WHERE reference = :r', ['r' => $reference]);
            $decoded = is_string($stored) ? json_decode($stored, true) : null;
            $order = is_array($decoded) && $decoded !== [] ? $decoded : $this->webhooks->blankOrder($reference);
            $order['reference'] = $reference;
        }
        $simulated = $this->webhooks->simulate($event, $order);
        $result = $this->processor->handle($simulated['headers'], $simulated['body'], true);
        $session->flash($result['status'] < 300 ? 'success' : 'error', $this->t->get('admin.webhooks.simulated', [
            'event' => $event,
            'status' => $result['status'],
            'outcome' => $this->t->get('admin.webhooks.outcomes.' . $result['outcome']),
        ]));

        return $this->responder->redirect($response, '/admin/webhook');
    }
}
