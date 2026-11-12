<?php

declare(strict_types=1);

namespace App\Orders;

use App\Supplier\Model\SupplierOrderData;
use App\Support\Clock;
use App\Support\Db;
use App\Support\Deferred;
use App\Support\Money;
use App\Support\Translator;
use Psr\Log\LoggerInterface;

/**
 * Porta lo stato del fornitore (webhook, rilettura periodica, rilettura a mano: stesso codice) sull'ordine al
 * fornitore e sull'ordine del cliente (docs/06 § Webhook). Gli stati non tornano mai indietro, tranne cancelled
 * che vale sempre ed è definitivo. Le email partono dopo il salvataggio, rinviate (Deferred).
 */
final class SupplierOrderUpdater
{
    /** Ordine degli stati: error, simulated e pending sono locali e cedono a qualsiasi stato del fornitore. */
    private const RANK = ['error' => 0, 'simulated' => 0, 'pending' => 1, 'received' => 2, 'confirmed' => 3, 'paid' => 4, 'shipped' => 5];

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly OrderRepository $orders,
        private readonly OrderMailer $orderMailer,
        private readonly SupplierOrderMailer $supplierMailer,
        private readonly TrackingPresenter $tracking,
        private readonly Deferred $deferred,
        private readonly Translator $t,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** L'ordine al fornitore si trova dal reference, altrimenti dall'id del fornitore. @return array<string, mixed>|null */
    public function find(SupplierOrderData $data): ?array
    {
        if ($data->reference !== '') {
            $row = $this->db->one('SELECT * FROM supplier_orders WHERE reference = :r', ['r' => $data->reference]);
            if ($row !== null) {
                return $row;
            }
        }
        if ($data->id !== null) {
            return $this->db->one('SELECT * FROM supplier_orders WHERE wtb_order_id = :i ORDER BY id DESC LIMIT 1', ['i' => $data->id]);
        }

        return null;
    }

    /**
     * @param string $event evento del fornitore (order.shipped…) o "poll" / "refresh"
     * @return array{outcome: 'applied'|'stale'|'unknown_order', supplier_order_id: ?int, changes: list<string>}
     */
    public function apply(SupplierOrderData $data, string $event): array
    {
        /** @var array{outcome: 'applied'|'stale'|'unknown_order', supplier_order_id: ?int, changes: list<string>, mails: list<array{0: string, 1: callable(): void}>} $result */
        $result = $this->db->transaction(fn (Db $db): array => $this->applyLocked($db, $data, $event));
        foreach ($result['mails'] as [$label, $task]) {
            $this->deferred->add($label, $task);
        }
        if ($result['outcome'] !== 'stale') {
            $this->logger->info('Ordine al fornitore aggiornato', ['event' => $event, 'outcome' => $result['outcome'], 'supplier_order' => $result['supplier_order_id'], 'changes' => $result['changes']]);
        }

        return ['outcome' => $result['outcome'], 'supplier_order_id' => $result['supplier_order_id'], 'changes' => $result['changes']];
    }

    /** @return array{outcome: 'applied'|'stale'|'unknown_order', supplier_order_id: ?int, changes: list<string>, mails: list<array{0: string, 1: callable(): void}>} */
    private function applyLocked(Db $db, SupplierOrderData $data, string $event): array
    {
        $row = $this->find($data);
        if ($row === null) {
            return ['outcome' => 'unknown_order', 'supplier_order_id' => null, 'changes' => [], 'mails' => []];
        }
        $id = (int) $row['id'];
        $now = $this->clock->iso();
        $changes = [];
        $update = [];
        $mails = [];

        // Stato: monotono, cancelled sempre e definitivo.
        $current = (string) $row['status'];
        $status = $current;
        if ($current !== 'cancelled') {
            if ($data->status === 'cancelled') {
                $status = 'cancelled';
            } elseif ((self::RANK[$data->status] ?? 0) > (self::RANK[$current] ?? 0)) {
                $status = $data->status;
            }
        }
        if ($status !== $current) {
            $update['status'] = $status;
            $update['last_error'] = '';
            $changes[] = 'status:' . $current . '>' . $status;
        }
        if ($data->isPaid && (int) $row['is_paid'] === 0) {
            $update['is_paid'] = 1;
            $changes[] = 'paid';
        }
        if ($row['wtb_order_id'] === null && $data->id !== null) {
            $update['wtb_order_id'] = $data->id;
        }
        if ($data->number !== '' && $data->number !== (string) $row['wtb_number'] && !$data->isTest) {
            $update['wtb_number'] = $data->number;
        }
        if ($data->paymentInstructions !== null) {
            $json = (string) json_encode($data->paymentInstructions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== $row['payment_instructions']) {
                $update['payment_instructions'] = $json;
                $changes[] = 'payment_instructions';
            }
        }

        // Tracking: si aggiungono i numeri nuovi, nessuno sparisce.
        $known = $this->trackingNumbers((string) $row['tracking']);
        $tracking = (array) json_decode((string) $row['tracking'], true);
        $new = [];
        foreach ($data->tracking as $item) {
            if (!isset($known[$item['number']])) {
                $known[$item['number']] = true;
                $tracking[] = $item;
                $new[] = $item;
            }
        }
        if ($new !== []) {
            $update['tracking'] = (string) json_encode(array_values($tracking), JSON_UNESCAPED_SLASHES);
            $changes[] = 'tracking+' . count($new);
        }

        // Importi e righe: le differenze si segnalano all'admin (una volta per differenza).
        $order = $this->orders->find((int) $row['order_id']) ?? [];
        $differences = $this->differences($row, $data, (int) $row['order_id']);
        $warnings = array_values(array_filter((array) json_decode((string) $row['warnings'], true), 'is_string'));
        $freshDiffs = array_values(array_diff($differences, $warnings));
        if ($freshDiffs !== []) {
            $update['warnings'] = (string) json_encode(array_values(array_unique(array_merge($warnings, $freshDiffs))), JSON_UNESCAPED_UNICODE);
            $changes[] = 'differences';
        }
        if (!($data->totalCents === 0 && $data->lines === [])) {
            foreach (['subtotal_cents' => $data->subtotalCents, 'shipping_cents' => $data->shippingCents, 'vat_cents' => $data->vatCents, 'total_cents' => $data->totalCents] as $column => $value) {
                if ($row[$column] === null || (int) $row[$column] !== $value) {
                    $update[$column] = $value;
                }
            }
            $update['response_payload'] = (string) json_encode($data->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $update['last_event_at'] = $now;
        if ($changes !== []) {
            $update['updated_at'] = $now;
        }
        $db->update('supplier_orders', $update, 'id = :id', ['id' => $id]);
        $fresh = $db->one('SELECT * FROM supplier_orders WHERE id = :id', ['id' => $id]) ?? $row;

        // Ordine del cliente.
        if ($order !== []) {
            $mails = array_merge($mails, $this->customerEffects($db, $order, $fresh, $status !== $current && $status === 'shipped', $new));
            if ($status !== $current && $status === 'cancelled') {
                $mails[] = ['email admin: ordine al fornitore annullato', fn () => $this->supplierMailer->notify('cancelled', $order, $fresh)];
            }
            if ($freshDiffs !== []) {
                $mails[] = ['email admin: differenze del fornitore', fn () => $this->supplierMailer->notify('differences', $order, $fresh, $freshDiffs)];
            }
        }

        return ['outcome' => $changes === [] ? 'stale' : 'applied', 'supplier_order_id' => $id, 'changes' => $changes, 'mails' => $mails];
    }

    /**
     * Spedizione: ordine del cliente "shipped" e email col tracking (solo link di corrieri, TrackingPresenter);
     * per i numeri aggiunti dopo, email solo con quelli nuovi.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $supplierOrder
     * @param list<array{carrier: string, number: string, url: string}> $new
     * @return list<array{0: string, 1: callable(): void}>
     */
    private function customerEffects(Db $db, array $order, array $supplierOrder, bool $justShipped, array $new): array
    {
        $orderId = (int) $order['id'];
        if ($order['status'] === 'cancelled') {
            return $justShipped
                ? [['email admin: spedito un ordine annullato', fn () => $this->supplierMailer->notify('shipped_cancelled', $order, $supplierOrder)]]
                : [];
        }
        $manual = $this->trackingNumbers((string) ($order['manual_tracking'] ?? ''));
        $unseen = array_values(array_filter($new, static fn (array $t): bool => !isset($manual[$t['number']])));
        $wasShipped = $order['status'] === 'shipped';
        if ($justShipped && !$wasShipped) {
            $now = $this->clock->iso();
            $db->update('orders', ['status' => 'shipped', 'shipped_at' => $now, 'updated_at' => $now], "id = :id AND status IN ('pending', 'confirmed')", ['id' => $orderId]);

            return [['email cliente: ordine spedito', function () use ($orderId): void {
                $fresh = $this->orders->find($orderId);
                if ($fresh !== null) {
                    $this->orderMailer->orderShipped($fresh, $this->tracking->forOrder($orderId), false);
                }
            }]];
        }
        if ($unseen !== [] && ($wasShipped || $supplierOrder['status'] === 'shipped')) {
            $safe = array_map(fn (array $t): array => $this->tracking->safe($t), $unseen);

            return [['email cliente: tracking aggiornato', function () use ($orderId, $safe): void {
                $fresh = $this->orders->find($orderId);
                if ($fresh !== null) {
                    $this->orderMailer->orderShipped($fresh, $safe, true);
                }
            }]];
        }

        return [];
    }

    /**
     * Differenze tra il fornitore e ciò che sappiamo: totale rispetto al preventivo o all'ultimo valore visto,
     * paia per articolo rispetto alle righe dell'ordine.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function differences(array $row, SupplierOrderData $data, int $orderId): array
    {
        if ($data->totalCents === 0 && $data->lines === []) {
            return [];
        }
        $out = [];
        $reference = $row['total_cents'] ?? $row['expected_total_cents'];
        if ($reference !== null && abs((int) $reference - $data->totalCents) > 100) {
            $out[] = $this->t->get('admin.supplier.diff_total', ['total' => Money::format($data->totalCents), 'before' => Money::format((int) $reference)], 'it');
        }
        if ($data->lines !== []) {
            $theirs = [];
            foreach ($data->lines as $l) {
                $key = $l['sku'] . ' EU ' . $l['size_eu'] . ' · ' . $l['vat_regime'];
                $theirs[$key] = ($theirs[$key] ?? 0) + $l['quantity'];
            }
            $ours = [];
            foreach ($this->orders->lines($orderId) as $l) {
                $key = $l['sku'] . ' EU ' . $l['size_eu'] . ' · ' . $l['vat_regime'];
                $ours[$key] = ($ours[$key] ?? 0) + (int) $l['qty_immediate'] + (int) $l['qty_extended'];
            }
            ksort($theirs);
            ksort($ours);
            foreach (array_unique(array_merge(array_keys($ours), array_keys($theirs))) as $key) {
                if (($ours[$key] ?? 0) !== ($theirs[$key] ?? 0)) {
                    $out[] = $this->t->get('admin.supplier.diff_line', ['item' => $key, 'theirs' => $theirs[$key] ?? 0, 'ours' => $ours[$key] ?? 0], 'it');
                }
            }
        }

        return $out;
    }

    /** @return array<string, true> */
    private function trackingNumbers(string $json): array
    {
        $out = [];
        foreach ((array) json_decode($json, true) as $item) {
            if (is_array($item) && is_string($item['number'] ?? null)) {
                $out[$item['number']] = true;
            }
        }

        return $out;
    }
}
