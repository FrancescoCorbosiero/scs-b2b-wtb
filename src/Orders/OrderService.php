<?php

declare(strict_types=1);

namespace App\Orders;

use App\Pricing\TierCalculator;
use App\Pricing\Tier;
use App\Shipping\ShippingCalculator;
use App\Supplier\Model\SupplierQuote;
use App\Support\Clock;
use App\Support\Db;
use App\Tax\TaxPolicy;
use Psr\Log\LoggerInterface;

/**
 * Ciclo dell'ordine del cliente (docs/06): creazione dal checkout (righe e totali congelati), conferma del
 * pagamento con pro-forma, riallineamento, annullamento. Un errore SMTP non perde mai l'ordine.
 */
final class OrderService
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly OrderRepository $orders,
        private readonly ReceiptNumberer $receipts,
        private readonly OrderMailer $mailer,
        private readonly SupplierOrderService $supplierOrders,
        private readonly TrackingPresenter $tracking,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Salva la richiesta d'ordine in "pending" con anagrafica, righe e totali congelati.
     *
     * @param array<string, mixed> $customer cliente collegato
     * @param array<string, string> $form dati validati del checkout
     * @param array<string, mixed> $view riepilogo del carrello (CartService::build, con la ripartizione confermata)
     * @param array<string, array{immediate: ?int, extended: ?int}> $costs costi unitari dal preventivo (RISERVATI)
     */
    public function create(array $customer, array $form, array $view, ?SupplierQuote $quote, array $costs, bool $unverified, string $token, string $ip, string $userAgent, string $mode, string $locale): int
    {
        /** @var \App\Tax\TaxResult $tax */
        $tax = $view['tax'];
        /** @var \App\Shipping\ShippingZone $zone */
        $zone = $view['zone'];
        $now = $this->clock->iso();
        $billing = [
            'billing_street' => (string) $customer['address_street'],
            'billing_city' => (string) $customer['address_city'],
            'billing_zip' => (string) $customer['address_zip'],
            'billing_province' => (string) $customer['address_province'],
            'billing_country' => (string) $customer['country_code'],
        ];
        $ship = $form['ship_to_mode'] === 'other'
            ? [
                'ship_name' => $form['ship_name'], 'ship_company' => $form['ship_company'], 'ship_street' => $form['ship_street'],
                'ship_city' => $form['ship_city'], 'ship_zip' => $form['ship_zip'], 'ship_province' => $form['ship_province'],
                'ship_country' => $form['ship_country'], 'ship_phone' => $form['ship_phone'],
            ]
            : [
                'ship_name' => $form['contact_name'], 'ship_company' => (string) $customer['company'], 'ship_street' => $billing['billing_street'],
                'ship_city' => $billing['billing_city'], 'ship_zip' => $billing['billing_zip'], 'ship_province' => $billing['billing_province'],
                'ship_country' => $billing['billing_country'], 'ship_phone' => $form['phone'],
            ];
        $localCosts = $this->localCosts($view['lines']);

        return $this->db->transaction(function (Db $db) use ($customer, $form, $view, $quote, $costs, $unverified, $token, $ip, $userAgent, $mode, $locale, $tax, $zone, $now, $billing, $ship, $localCosts): int {
            $expectedCost = 0;
            $lines = [];
            foreach ($view['lines'] as $line) {
                $product = $line['product'];
                $unit = $line['unit'];
                $split = ['immediate' => ['qty' => 0, 'price' => null, 'ships' => ''], 'extended' => ['qty' => 0, 'price' => null, 'ships' => '']];
                foreach ($unit['split']['lines'] as $l) {
                    $split[$l['stock_type']] = ['qty' => $l['quantity'], 'price' => $l['unit_price_cents'], 'ships' => $l['ships_in_days']];
                }
                $key = (string) $unit['key'];
                $costImm = $costs[$key]['immediate'] ?? $localCosts[$key]['immediate'] ?? null;
                $costExt = $costs[$key]['extended'] ?? $localCosts[$key]['extended'] ?? null;
                $expectedCost += $split['immediate']['qty'] * (int) $costImm + $split['extended']['qty'] * (int) $costExt;
                $ships = array_values(array_filter([$split['immediate']['qty'] > 0 ? $split['immediate']['ships'] : '', $split['extended']['qty'] > 0 ? $split['extended']['ships'] : '']));
                $lines[] = [
                    'product_id' => (int) $product['id'],
                    'variant_id' => (int) $unit['variant_id'],
                    'wtb_variant_id' => (int) $unit['wtb_variant_id'],
                    'sku' => (string) $product['sku'],
                    'name' => (string) $product['name'],
                    'brand' => (string) $product['brand'],
                    'size_eu' => (string) $unit['size_eu'],
                    'size_us' => (string) $unit['size_us'],
                    'size_uk' => (string) $unit['size_uk'],
                    'ean' => (string) $unit['ean'],
                    'vat_regime' => (string) $unit['regime'],
                    'qty_immediate' => $split['immediate']['qty'],
                    'unit_price_immediate_cents' => $split['immediate']['qty'] > 0 ? $split['immediate']['price'] : null,
                    'qty_extended' => $split['extended']['qty'],
                    'unit_price_extended_cents' => $split['extended']['qty'] > 0 ? $split['extended']['price'] : null,
                    'line_total_cents' => (int) $unit['split']['total_cents'],
                    'ships_in_days' => implode(' / ', $ships),
                    'unit_cost_immediate_cents' => $split['immediate']['qty'] > 0 ? $costImm : null,
                    'unit_cost_extended_cents' => $split['extended']['qty'] > 0 ? $costExt : null,
                ];
            }
            $orderId = $db->insert('orders', $billing + $ship + [
                'user_id' => (int) $customer['id'],
                'status' => 'pending',
                'mode' => $mode,
                'locale' => $locale,
                'checkout_token' => $token,
                'company' => (string) $customer['company'],
                'vat_number' => (string) $customer['vat_number'],
                'contact_name' => $form['contact_name'],
                'email' => $form['email'],
                'phone' => $form['phone'],
                'ship_to_mode' => $form['ship_to_mode'],
                'shipping_zone' => $zone->code,
                'shipping_fee_cents' => $zone->feeCents,
                'shipping_free_from' => $zone->freeFromItems,
                'vat_scheme' => $tax->scheme,
                'vat_rate_bp' => $tax->vatRateBp,
                'shipping_regime' => (string) $view['shipping_regime'],
                'pairs' => (int) $view['pairs'],
                'subtotal_margin_cents' => $tax->subtotalMarginCents,
                'subtotal_ordinary_cents' => $tax->subtotalOrdinaryCents,
                'shipping_cents' => $tax->shippingCents,
                'vat_cents' => $tax->vatCents,
                'total_cents' => $tax->totalCents,
                'departure_days' => $view['departure'] === null ? '' : $view['departure'][0] . '-' . $view['departure'][1],
                'delivery_days' => $view['delivery'] === null ? '' : $view['delivery'][0] . '-' . $view['delivery'][1],
                'expected_cost_cents' => $quote !== null ? $quote->subtotalCents : $expectedCost,
                'wtb_shipping_cost_cents' => $quote?->shippingTotalCents,
                'quote_snapshot' => $quote === null ? null : (string) json_encode($quote->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'quote_unverified' => $unverified ? 1 : 0,
                'notes' => $form['notes'],
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($lines as $line) {
                $db->insert('order_lines', $line + ['order_id' => $orderId]);
            }

            return $orderId;
        });
    }

    /** Invia le email della richiesta d'ordine (cliente e admin) e segna l'esito. */
    public function notifyCreated(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return;
        }
        $lines = $this->orders->lines($orderId);
        $customerSent = $this->mailer->orderReceived($order, $lines);
        $adminSent = $this->mailer->orderReceivedAdmin($order, $lines);
        $this->db->update('orders', ['email_customer_sent' => $customerSent ? 1 : 0, 'email_admin_sent' => $adminSent ? 1 : 0], 'id = :id', ['id' => $orderId]);
    }

    /**
     * Conferma del pagamento ricevuto (docs/06): numero pro-forma, email con PDF.
     *
     * @return array{status: 'ok'|'not_pending'|'vies_required', receipt?: string, mailed?: bool, messages?: list<array{type: string, text: string}>}
     */
    public function confirm(int $orderId, string $by, bool $viesChecked): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null || $order['status'] !== 'pending') {
            return ['status' => 'not_pending'];
        }
        if ($this->requiresVies($order) && !$viesChecked) {
            return ['status' => 'vies_required'];
        }
        $receipt = $this->receipts->next((int) $this->clock->now()->format('Y'));
        $now = $this->clock->iso();
        $this->db->update('orders', [
            'status' => 'confirmed',
            'receipt_number' => $receipt,
            'confirmed_at' => $now,
            'vies_checked_at' => $this->requiresVies($order) ? $now : null,
            'updated_at' => $now,
        ], "id = :id AND status = 'pending'", ['id' => $orderId]);
        $this->logger->info('Ordine confermato', ['order' => $orderId, 'receipt' => $receipt, 'by' => $by]);
        $confirmed = $this->orders->find($orderId) ?? $order;
        $mailed = $this->mailer->orderConfirmed($confirmed, $this->orders->lines($orderId));
        // Con WTB_ORDER_TRIGGER=on_confirm parte l'ordine al fornitore; se non riesce la conferma resta valida.
        $messages = $this->supplierOrders->afterConfirm($orderId, $by);

        return ['status' => 'ok', 'receipt' => $receipt, 'mailed' => $mailed, 'messages' => $messages];
    }

    /** Clienti UE con righe ordinarie: alla conferma serve la spunta "P.IVA verificata su VIES" (docs/04). @param array<string, mixed> $order */
    public function requiresVies(array $order): bool
    {
        return $order['vat_scheme'] === 'intra_eu' && (int) $order['subtotal_ordinary_cents'] > 0;
    }

    public function cancel(int $orderId, string $by): bool
    {
        $now = $this->clock->iso();
        $done = $this->db->update('orders', ['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now], "id = :id AND status IN ('pending', 'confirmed')", ['id' => $orderId]) === 1;
        if ($done) {
            $this->logger->info('Ordine annullato', ['order' => $orderId, 'by' => $by]);
        }

        return $done;
    }

    /**
     * Riallinea le quantità alla disponibilità (solo in riduzione, prezzi congelati): si tolgono prima le paia
     * in stock esteso. Totali e spedizione ricalcolati con le regole congelate nell'ordine.
     *
     * @param array<int, int> $quantities id riga => nuove paia
     * @return array{status: 'ok'|'not_allowed'|'empty', changed?: int, mailed?: bool}
     */
    public function realign(int $orderId, array $quantities, string $by, bool $notify): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null || !in_array($order['status'], ['pending', 'confirmed'], true) || $this->hasLiveSupplierOrder($orderId)) {
            return ['status' => 'not_allowed'];
        }
        $lines = $this->orders->lines($orderId);
        $changed = 0;
        $remaining = 0;
        foreach ($lines as $line) {
            $current = (int) $line['qty_immediate'] + (int) $line['qty_extended'];
            $new = max(0, min($current, $quantities[(int) $line['id']] ?? $current));
            $remaining += $new;
            if ($new !== $current) {
                $changed++;
            }
        }
        if ($remaining === 0) {
            return ['status' => 'empty'];
        }
        if ($changed === 0) {
            return ['status' => 'ok', 'changed' => 0, 'mailed' => false];
        }
        $this->db->transaction(function (Db $db) use ($order, $lines, $quantities, $orderId): void {
            $margin = 0;
            $ordinary = 0;
            $pairs = 0;
            foreach ($lines as $line) {
                $current = (int) $line['qty_immediate'] + (int) $line['qty_extended'];
                $new = max(0, min($current, $quantities[(int) $line['id']] ?? $current));
                if ($new === 0) {
                    $db->run('DELETE FROM order_lines WHERE id = :id', ['id' => $line['id']]);
                    continue;
                }
                $tiers = [new Tier('immediate', (int) $line['qty_immediate'], (int) $line['unit_price_immediate_cents'], ''), new Tier('extended', (int) $line['qty_extended'], (int) $line['unit_price_extended_cents'], '')];
                $split = TierCalculator::split($tiers, $new);
                $imm = 0;
                $ext = 0;
                foreach ($split['lines'] as $l) {
                    $l['stock_type'] === 'immediate' ? $imm = $l['quantity'] : $ext = $l['quantity'];
                }
                $db->update('order_lines', [
                    'qty_immediate' => $imm,
                    'qty_extended' => $ext,
                    'unit_price_immediate_cents' => $imm > 0 ? $line['unit_price_immediate_cents'] : null,
                    'unit_price_extended_cents' => $ext > 0 ? $line['unit_price_extended_cents'] : null,
                    'unit_cost_immediate_cents' => $imm > 0 ? $line['unit_cost_immediate_cents'] : null,
                    'unit_cost_extended_cents' => $ext > 0 ? $line['unit_cost_extended_cents'] : null,
                    'line_total_cents' => $split['total_cents'],
                ], 'id = :id', ['id' => $line['id']]);
                $line['vat_regime'] === 'margin' ? $margin += $split['total_cents'] : $ordinary += $split['total_cents'];
                $pairs += $new;
            }
            $shipping = ShippingCalculator::fee((int) $order['shipping_fee_cents'], $order['shipping_free_from'] === null ? null : (int) $order['shipping_free_from'], $pairs);
            $tax = TaxPolicy::calculate((string) $order['vat_scheme'], intdiv((int) $order['vat_rate_bp'], 100), (string) $order['shipping_regime'], $margin, $ordinary, $shipping);
            $cost = $db->int(
                'SELECT COALESCE(SUM(qty_immediate * COALESCE(unit_cost_immediate_cents, 0) + qty_extended * COALESCE(unit_cost_extended_cents, 0)), 0) FROM order_lines WHERE order_id = :o',
                ['o' => $orderId],
            );
            $db->update('orders', [
                'pairs' => $pairs,
                'subtotal_margin_cents' => $tax->subtotalMarginCents,
                'subtotal_ordinary_cents' => $tax->subtotalOrdinaryCents,
                'shipping_cents' => $tax->shippingCents,
                'vat_cents' => $tax->vatCents,
                'total_cents' => $tax->totalCents,
                'expected_cost_cents' => $cost,
                'updated_at' => $this->clock->iso(),
            ], 'id = :id', ['id' => $orderId]);
        });
        $this->logger->info('Ordine riallineato', ['order' => $orderId, 'by' => $by, 'lines_changed' => $changed]);
        $mailed = false;
        if ($notify) {
            $fresh = $this->orders->find($orderId);
            $mailed = $fresh !== null && $this->mailer->orderUpdated($fresh, $this->orders->lines($orderId));
        }

        return ['status' => 'ok', 'changed' => $changed, 'mailed' => $mailed];
    }

    /**
     * "Segna come spedito" a mano, con tracking: ripiego se il webhook del fornitore non arriva (docs/06).
     * Da confermato a spedito con email al cliente; su un ordine già spedito aggiunge il numero e avvisa solo di quello.
     *
     * @return array{status: 'ok'|'not_allowed'|'invalid', mailed?: bool}
     */
    public function markShipped(int $orderId, string $carrier, string $number, string $url, string $by): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null || !in_array($order['status'], ['confirmed', 'shipped'], true)) {
            return ['status' => 'not_allowed'];
        }
        $carrier = trim($carrier);
        $number = trim($number);
        $url = trim($url);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/\-]{2,99}$/', $number) !== 1 || mb_strlen($carrier) > 60
            || ($url !== '' && preg_match('#^https://[^\s"<>]{4,500}$#', $url) !== 1)) {
            return ['status' => 'invalid'];
        }
        $item = ['carrier' => $carrier, 'number' => $number, 'url' => $url];
        $existing = array_values(array_filter((array) json_decode((string) ($order['manual_tracking'] ?? ''), true), 'is_array'));
        foreach ($existing as $known) {
            if (($known['number'] ?? null) === $number) {
                return ['status' => 'ok', 'mailed' => false];
            }
        }
        $existing[] = $item;
        $wasShipped = $order['status'] === 'shipped';
        $now = $this->clock->iso();
        $this->db->update('orders', [
            'manual_tracking' => (string) json_encode($existing, JSON_UNESCAPED_SLASHES),
            'status' => 'shipped',
            'shipped_at' => $order['shipped_at'] ?? $now,
            'updated_at' => $now,
        ], 'id = :id', ['id' => $orderId]);
        $this->logger->info('Ordine segnato come spedito a mano', ['order' => $orderId, 'by' => $by]);
        $fresh = $this->orders->find($orderId) ?? $order;
        $mailed = $this->mailer->orderShipped($fresh, $wasShipped ? [$this->tracking->safe($item)] : $this->tracking->forOrder($orderId), $wasShipped);

        return ['status' => 'ok', 'mailed' => $mailed];
    }

    /** Esiste un ordine al fornitore ancora valido (non annullato, non in errore)? */
    public function hasLiveSupplierOrder(int $orderId): bool
    {
        return $this->supplierOrders->live($orderId) !== null;
    }

    /**
     * Costi correnti delle offerte (quando il preventivo del fornitore non è disponibile). RISERVATI.
     *
     * @param list<array{product: array<string, mixed>, unit: array<string, mixed>}> $lines
     * @return array<string, array{immediate: ?int, extended: ?int}>
     */
    private function localCosts(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $unit = $line['unit'];
            $rows = $this->db->all(
                'SELECT stock_type, cost_cents FROM offers WHERE variant_id = :v AND vat_regime = :r',
                ['v' => $unit['variant_id'], 'r' => $unit['regime']],
            );
            $costs = ['immediate' => null, 'extended' => null];
            foreach ($rows as $row) {
                $costs[(string) $row['stock_type']] = (int) $row['cost_cents'];
            }
            $out[(string) $unit['key']] = $costs;
        }

        return $out;
    }
}
