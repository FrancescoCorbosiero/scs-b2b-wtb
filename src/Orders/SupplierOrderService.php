<?php

declare(strict_types=1);

namespace App\Orders;

use App\Settings\SettingsService;
use App\Supplier\Exception\SupplierException;
use App\Supplier\Exception\SupplierRejectedException;
use App\Supplier\Model\SupplierOrderData;
use App\Supplier\Model\SupplierOrderRequest;
use App\Supplier\SupplierOrders;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\Money;
use App\Support\Translator;
use Psr\Log\LoggerInterface;

/**
 * Ordine presso il fornitore (docs/06 § Ordine a WTB): controlli (kill switch, modalità, tetto di spesa),
 * preventivo, creazione idempotente col reference, istruzioni di pagamento, avvisi all'admin.
 * Mai prezzi né note del cliente nel payload.
 */
final class SupplierOrderService
{
    public const TRIGGERS = ['on_confirm', 'on_request', 'manual'];

    /** Una riga "pending" più vecchia di così è una creazione interrotta: si può ritentare (stesso reference). */
    public const PENDING_TIMEOUT = 600;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly SupplierOrders $supplier,
        private readonly OrderRepository $orders,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly SupplierOrderMailer $mailer,
        private readonly Translator $t,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function trigger(): string
    {
        $trigger = $this->config->string('WTB_ORDER_TRIGGER', 'on_confirm');

        return in_array($trigger, self::TRIGGERS, true) ? $trigger : 'on_confirm';
    }

    public function enabled(): bool
    {
        return $this->config->bool('WTB_ORDERS_ENABLED', true);
    }

    public function mode(): string
    {
        return $this->supplier->mode();
    }

    /** Ordine al fornitore ancora valido per l'ordine (non annullato, non in errore, non interrotto). @return array<string, mixed>|null */
    public function live(int $orderId): ?array
    {
        return $this->db->one(
            "SELECT * FROM supplier_orders WHERE order_id = :o AND status NOT IN ('cancelled', 'error')
               AND NOT (status = 'pending' AND updated_at < :stale)
             ORDER BY id DESC LIMIT 1",
            ['o' => $orderId, 'stale' => $this->clock->isoAgo(self::PENDING_TIMEOUT)],
        );
    }

    /**
     * Si può creare (o ritentare) l'ordine al fornitore da qui? Motivo del no, null se si può.
     *
     * @param array<string, mixed> $order
     */
    public function blocker(array $order): ?string
    {
        if (!$this->enabled()) {
            return 'disabled';
        }
        if ($order['mode'] !== $this->supplier->mode()) {
            return 'other_mode';
        }
        if (in_array($order['status'], ['cancelled', 'shipped'], true)) {
            return 'closed';
        }

        return $this->live((int) $order['id']) !== null ? 'existing' : null;
    }

    /**
     * Crea (o ritenta in sicurezza) l'ordine al fornitore per un ordine del cliente.
     *
     * @return array{status: 'created'|'simulated'|'existing'|'error'|'refused', message: string, supplier_order_id?: int}
     */
    public function create(int $orderId, string $by): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return ['status' => 'refused', 'message' => $this->t->get('admin.supplier.result.not_found')];
        }
        $blocker = $this->blocker($order);
        if ($blocker === 'existing') {
            $live = $this->live($orderId) ?? [];

            return ['status' => 'existing', 'message' => $this->t->get('admin.supplier.result.existing', ['reference' => (string) ($live['reference'] ?? '')]), 'supplier_order_id' => (int) ($live['id'] ?? 0)];
        }
        if ($blocker !== null) {
            return ['status' => 'refused', 'message' => $this->t->get('admin.supplier.result.' . $blocker, ['order' => strtoupper((string) $order['mode']), 'current' => strtoupper($this->supplier->mode())])];
        }
        $lines = $this->orders->lines($orderId);
        if ($lines === []) {
            return ['status' => 'refused', 'message' => $this->t->get('admin.supplier.result.no_lines')];
        }

        // Reference: lo stesso dopo un errore o una creazione interrotta (ritentare è sicuro), nuovo solo dopo un annullamento.
        $last = $this->db->one('SELECT * FROM supplier_orders WHERE order_id = :o ORDER BY attempt DESC, id DESC LIMIT 1', ['o' => $orderId]);
        $attempt = $last === null ? 1 : (in_array($last['status'], ['error', 'pending'], true) ? (int) $last['attempt'] : (int) $last['attempt'] + 1);
        $reference = sprintf('%s-%d-%d', $this->config->string('WTB_REFERENCE_PREFIX', 'SCSW'), $orderId, $attempt);
        $request = $this->request($order, $lines, $reference);

        // 1. Preventivo: se mancano paia non si crea nulla; l'admin riallinea e ritenta.
        $quoteTotal = null;
        try {
            $quote = $this->supplier->quote($request);
            $quoteTotal = $quote->totalCents;
            $cap = $this->config->int('WTB_MAX_ORDER_EUR', 0);
            if ($cap > 0 && $quote->totalCents > $cap * 100) {
                return ['status' => 'refused', 'message' => $this->t->get('admin.supplier.result.over_cap', ['cost' => Money::format($quote->totalCents), 'cap' => $cap])];
            }
        } catch (SupplierRejectedException $e) {
            $detail = implode(' · ', $e->hasStockIssues() ? $e->stockIssues() : [$e->getMessage()]);
            $message = $this->t->get($e->hasStockIssues() ? 'admin.supplier.result.missing_pairs' : 'admin.supplier.result.rejected', ['detail' => $detail]);
            $id = $this->record($order, $reference, $attempt, $request, 'error', $message, $by, $last);
            $this->mailer->notify('problem', $order, $this->row($id), [$message]);

            return ['status' => 'error', 'message' => $message, 'supplier_order_id' => $id];
        } catch (SupplierException $e) {
            // Preventivo non disponibile: si prova comunque la creazione, che ha i suoi tentativi.
            $this->logger->warning('Ordine al fornitore: preventivo non disponibile prima della creazione', ['order' => $orderId, 'error' => $e->getMessage()]);
        }

        // 2. Creazione: la riga nasce "pending" prima della chiamata, così un'interruzione lascia traccia.
        $id = $this->record($order, $reference, $attempt, $request, 'pending', '', $by, $last);
        try {
            $result = $this->supplier->createOrder($request);
        } catch (SupplierRejectedException $e) {
            $message = $this->t->get('admin.supplier.result.rejected', ['detail' => $e->getMessage()]);
            $this->fail($id, $message);
            $this->mailer->notify('problem', $order, $this->row($id), [$message]);

            return ['status' => 'error', 'message' => $message, 'supplier_order_id' => $id];
        } catch (SupplierException $e) {
            $message = $this->t->get('admin.supplier.result.failed', ['detail' => $e->getMessage()]);
            $this->fail($id, $message);
            $this->mailer->notify('problem', $order, $this->row($id), [$message]);

            return ['status' => 'error', 'message' => $message, 'supplier_order_id' => $id];
        }

        // 3. Risposta: 201 nuovo o 200 stesso ordine; in test e fixture è una simulazione.
        $data = $result->order;
        $simulated = $data->isTest || $this->supplier->mode() !== 'live';
        $warnings = [];
        if ($quoteTotal !== null && abs($data->totalCents - $quoteTotal) > 100) {
            $warnings[] = $this->t->get('admin.supplier.result.mismatch', ['total' => Money::format($data->totalCents), 'quote' => Money::format($quoteTotal)]);
        }
        $this->db->update('supplier_orders', [
            'status' => $simulated ? 'simulated' : $data->status,
            'wtb_order_id' => $data->id,
            'wtb_number' => $data->isTest ? '' : $data->number,
            'is_paid' => $data->isPaid ? 1 : 0,
            'payment_method' => $data->paymentMethod !== '' ? $data->paymentMethod : $request->paymentMethod,
            'payment_instructions' => $data->paymentInstructions === null ? null : (string) json_encode($data->paymentInstructions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'subtotal_cents' => $data->subtotalCents,
            'shipping_cents' => $data->shippingCents,
            'vat_cents' => $data->vatCents,
            'total_cents' => $data->totalCents,
            'expected_total_cents' => $quoteTotal,
            'tracking' => (string) json_encode($data->tracking, JSON_UNESCAPED_SLASHES),
            'response_payload' => (string) json_encode($data->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_error' => '',
            'warnings' => (string) json_encode($warnings, JSON_UNESCAPED_UNICODE),
            'updated_at' => $this->clock->iso(),
        ], 'id = :id', ['id' => $id]);
        $this->logger->info('Ordine al fornitore creato', ['order' => $orderId, 'reference' => $reference, 'simulated' => $simulated, 'existing' => !$result->created, 'by' => $by]);
        $this->mailer->notify($simulated ? 'simulated' : 'created', $order, $this->row($id), $warnings);
        $message = $this->t->get($simulated ? 'admin.supplier.result.simulated' : 'admin.supplier.result.created', [
            'number' => $data->number, 'reference' => $reference, 'mode' => strtoupper($this->supplier->mode()),
        ]);

        return [
            'status' => $simulated ? 'simulated' : 'created',
            'message' => trim($message . ' ' . implode(' ', $warnings)),
            'supplier_order_id' => $id,
        ];
    }

    /** Dopo la richiesta del cliente (WTB_ORDER_TRIGGER=on_request): gli esiti arrivano all'admin per email. */
    public function afterRequest(int $orderId): void
    {
        if ($this->trigger() === 'on_request') {
            $result = $this->create($orderId, 'checkout');
            $this->logger->info('Ordine al fornitore alla richiesta del cliente', ['order' => $orderId, 'status' => $result['status']]);
        }
    }

    /**
     * Dopo la conferma del pagamento (WTB_ORDER_TRIGGER=on_confirm, D-009). La conferma resta valida comunque.
     *
     * @return list<array{type: string, text: string}>
     */
    public function afterConfirm(int $orderId, string $by): array
    {
        if ($this->trigger() !== 'on_confirm') {
            return [['type' => 'info', 'text' => $this->t->get('admin.supplier.result.trigger_' . $this->trigger())]];
        }
        $result = $this->create($orderId, $by);

        return [['type' => self::flashType($result['status']), 'text' => $result['message']]];
    }

    public static function flashType(string $status): string
    {
        return match ($status) {
            'created' => 'success',
            'simulated', 'existing' => 'info',
            'refused' => 'warning',
            default => 'error',
        };
    }

    /**
     * Payload per il fornitore: una riga per variante + regime, il tipo di stock lo assegna il fornitore.
     * Mai prezzi né note del cliente.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     */
    public function request(array $order, array $lines, string $reference): SupplierOrderRequest
    {
        $email = $this->config->string('WTB_SHIP_TO_EMAIL', 'admin') === 'customer'
            ? (string) $order['email']
            : ($this->settings->list('company.admin_emails')[0] ?? (string) $order['email']);
        $quantities = [];
        foreach ($lines as $l) {
            $key = (int) $l['wtb_variant_id'] . '|' . $l['vat_regime'];
            $quantities[$key] = ($quantities[$key] ?? 0) + (int) $l['qty_immediate'] + (int) $l['qty_extended'];
        }
        $payloadLines = [];
        foreach ($quantities as $key => $quantity) {
            [$variant, $regime] = explode('|', $key, 2);
            if ($quantity > 0) {
                $payloadLines[] = ['variant_id' => (int) $variant, 'vat_regime' => $regime, 'quantity' => $quantity];
            }
        }

        return new SupplierOrderRequest(
            $reference,
            $payloadLines,
            array_filter([
                'full_name' => (string) $order['ship_name'],
                'company' => (string) $order['ship_company'],
                'street' => (string) $order['ship_street'],
                'city' => (string) $order['ship_city'],
                'zip_code' => (string) $order['ship_zip'],
                'province' => (string) $order['ship_province'],
                'country' => (string) $order['ship_country'],
                'phone' => (string) ($order['ship_phone'] !== '' ? $order['ship_phone'] : $order['phone']),
                'email' => $email,
            ], static fn (string $v): bool => $v !== ''),
            $this->config->string('WTB_PAYMENT_METHOD', 'bank_transfer') === 'balance' ? 'balance' : 'bank_transfer',
            '',
        );
    }

    /** Lo stato attuale presso il fornitore, letto dall'API (dettaglio admin e rilettura a mano). */
    public function fetch(string $reference): ?SupplierOrderData
    {
        return $this->supplier->findByReference($reference);
    }

    /** @param array<string, mixed>|null $last */
    private function record(array $order, string $reference, int $attempt, SupplierOrderRequest $request, string $status, string $error, string $by, ?array $last): int
    {
        $now = $this->clock->iso();
        $payload = (string) json_encode($request->toOrderPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($last !== null && (string) $last['reference'] === $reference) {
            $this->db->update('supplier_orders', [
                'status' => $status, 'request_payload' => $payload, 'last_error' => mb_substr($error, 0, 2000), 'updated_at' => $now,
            ], 'id = :id', ['id' => $last['id']]);

            return (int) $last['id'];
        }

        try {
            return $this->db->insert('supplier_orders', [
                'order_id' => (int) $order['id'],
                'mode' => $this->supplier->mode(),
                'reference' => $reference,
                'attempt' => $attempt,
                'status' => $status,
                'payment_method' => $request->paymentMethod,
                'request_payload' => $payload,
                'last_error' => mb_substr($error, 0, 2000),
                'created_by' => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $e) {
            // Due creazioni simultanee (doppio clic, on_request + admin): stessa riga e stesso reference,
            // che il fornitore tratta come lo stesso ordine.
            $existing = $this->db->value('SELECT id FROM supplier_orders WHERE reference = :r', ['r' => $reference]);
            if (!str_contains($e->getMessage(), 'UNIQUE') || $existing === null) {
                throw $e;
            }

            return (int) $existing;
        }
    }

    private function fail(int $id, string $message): void
    {
        $this->db->update('supplier_orders', ['status' => 'error', 'last_error' => mb_substr($message, 0, 2000), 'updated_at' => $this->clock->iso()], 'id = :id', ['id' => $id]);
        $this->logger->error('Ordine al fornitore non creato', ['supplier_order' => $id, 'error' => $message]);
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        return $this->db->one('SELECT * FROM supplier_orders WHERE id = :id', ['id' => $id]);
    }
}
