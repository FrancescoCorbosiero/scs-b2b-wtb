<?php

declare(strict_types=1);

namespace App\Supplier\Wtb\Fixture;

use App\Supplier\Wtb\Http\TransportResponse;
use App\Supplier\Wtb\WtbMapper;
use App\Support\Money;

/**
 * Simula /quote/ e /orders/ in modalità fixture con le regole osservate in M0 (docs/03 § Esiti M0):
 * prima la pronta consegna, poi lo stock esteso; tariffa di zona solo sulle paia in pronta consegna;
 * trasporto dello stock esteso a scaglioni; IVA 22% su righe ordinarie e spedizione se c'è una riga
 * ordinaria; errori 400 con la stessa forma del fornitore.
 */
final class Simulator
{
    /** @var array<string, array{sku: string, size_eu: string, available: int, price: string}> chiave variant|regime|tipo */
    private array $stock = [];

    /** @var array<int, array{sku: string, size_eu: string}> */
    private array $variants = [];

    /**
     * @param array<mixed> $stock
     * @param array<mixed> $shipping
     * @param array<mixed> $orderTemplate
     */
    public function __construct(array $stock, private readonly array $shipping, private readonly array $orderTemplate)
    {
        foreach (is_array($stock['results'] ?? null) ? $stock['results'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->stock[$row['variant_id'] . '|' . $row['vat_regime'] . '|' . $row['stock_type']] = [
                'sku' => (string) $row['sku'], 'size_eu' => (string) $row['size_eu'], 'available' => (int) $row['available'], 'price' => (string) $row['price'],
            ];
            $this->variants[(int) $row['variant_id']] = ['sku' => (string) $row['sku'], 'size_eu' => (string) $row['size_eu']];
        }
    }

    /** @param array<mixed> $payload */
    public function quote(array $payload): TransportResponse
    {
        $result = $this->compute($payload);

        return $result instanceof TransportResponse ? $result : TransportResponse::json(200, $result);
    }

    /** @param array<mixed> $payload */
    public function order(array $payload): TransportResponse
    {
        if (!is_string($payload['reference'] ?? null) || $payload['reference'] === '') {
            return TransportResponse::json(400, ['reference' => 'Required: your order number (used to avoid duplicates).']);
        }
        $shipTo = is_array($payload['ship_to'] ?? null) ? $payload['ship_to'] : [];
        foreach (['full_name', 'street', 'city', 'zip_code', 'country'] as $field) {
            if (!is_string($shipTo[$field] ?? null) || trim($shipTo[$field]) === '') {
                return TransportResponse::json(400, ['ship_to' => 'Missing field: ' . $field]);
            }
        }
        if (!in_array($payload['payment_method'] ?? 'balance', ['balance', 'bank_transfer'], true)) {
            return TransportResponse::json(400, ['payment_method' => 'Via API orders are paid with prepaid credit (balance) or advance bank transfer on proforma (bank_transfer).']);
        }
        $quote = $this->compute($payload);
        if ($quote instanceof TransportResponse) {
            return $quote;
        }
        $id = 9000 + (crc32($payload['reference']) % 999);
        $order = $this->orderTemplate;
        $order['id'] = $id;
        $order['number'] = 'WTB/2026/11/' . $id;
        $order['reference'] = $payload['reference'];
        $order['status'] = 'received';
        $order['payment_method'] = $payload['payment_method'] ?? 'balance';
        $order['is_paid'] = ($payload['payment_method'] ?? 'balance') === 'balance';
        $order['subtotal'] = $quote['subtotal'];
        $order['shipping'] = $quote['shipping_total'];
        $order['vat'] = $quote['vat'];
        $order['total'] = $quote['total'];
        $order['lines'] = array_map(fn (array $l): array => [
            'sku' => $l['sku'], 'name' => $l['sku'], 'size_eu' => $l['size_eu'], 'vat_regime' => $l['vat_regime'],
            'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
        ], $quote['lines']);
        $order['tracking'] = [];
        if ($order['payment_method'] === 'bank_transfer') {
            $order['payment_instructions'] = [
                'iban' => 'IT60X0542811101000000123456',
                'beneficiary' => 'Fornitore Demo S.r.l.',
                'reason' => $order['number'],
                'amount' => $quote['total'],
            ];
        } else {
            $order['payment_instructions'] = null;
        }

        return TransportResponse::json(201, $order);
    }

    /**
     * @param array<mixed> $payload
     * @return array<string, mixed>|TransportResponse
     */
    private function compute(array $payload): array|TransportResponse
    {
        $country = strtoupper((string) (is_array($payload['ship_to'] ?? null) ? ($payload['ship_to']['country'] ?? 'IT') : 'IT'));
        $zone = null;
        $served = [];
        foreach (is_array($this->shipping['zones'] ?? null) ? $this->shipping['zones'] : [] as $z) {
            $served = array_merge($served, (array) $z['countries']);
            if (in_array($country, (array) $z['countries'], true)) {
                $zone = $z;
            }
        }
        if ($zone === null) {
            return TransportResponse::json(400, ['ship_to' => 'Shipping to ' . $country . ' is not available for retailers. Countries served: ' . implode(', ', $served)]);
        }
        $lines = [];
        $errors = [];
        $immPieces = 0;
        $extPieces = 0;
        $subtotal = 0;
        $ordinaryTaxable = 0;
        $hasOrdinary = false;
        $hasMargin = false;
        foreach (is_array($payload['lines'] ?? null) ? $payload['lines'] : [] as $line) {
            $variantId = $this->resolveVariant(is_array($line) ? $line : []);
            $quantity = is_array($line) && is_int($line['quantity'] ?? null) ? $line['quantity'] : 0;
            if ($variantId === null || $quantity <= 0) {
                return TransportResponse::json(400, ['pieces' => null, 'subtotal' => null, 'shipping' => null, 'extended_stock_transport' => null, 'shipping_total' => null, 'vat' => null, 'total' => null, 'vat_note' => null, 'lines' => []]);
            }
            $regime = is_string($line['vat_regime'] ?? null) ? $line['vat_regime']
                : (isset($this->stock[$variantId . '|margin|immediate']) || isset($this->stock[$variantId . '|margin|extended']) ? 'margin' : 'ordinary');
            $imm = $this->stock[$variantId . '|' . $regime . '|immediate'] ?? null;
            $ext = $this->stock[$variantId . '|' . $regime . '|extended'] ?? null;
            $available = ($imm['available'] ?? 0) + ($ext['available'] ?? 0);
            $info = $this->variants[$variantId];
            if ($available < $quantity) {
                $errors[] = sprintf('%s EU %s (%s): %s', $info['sku'], $info['size_eu'], $regime, $available === 0 ? 'not available.' : 'only ' . $available . ' available.');
                continue;
            }
            $qImm = min($quantity, $imm['available'] ?? 0);
            $qExt = $quantity - $qImm;
            foreach ([['immediate', $qImm, $imm], ['extended', $qExt, $ext]] as [$type, $q, $offer]) {
                if ($q <= 0 || $offer === null) {
                    continue;
                }
                $cost = Money::parse($offer['price']);
                $subtotal += $cost * $q;
                if ($regime === 'ordinary') {
                    $ordinaryTaxable += $cost * $q;
                    $hasOrdinary = true;
                } else {
                    $hasMargin = true;
                }
                $type === 'immediate' ? $immPieces += $q : $extPieces += $q;
                $lines[] = ['sku' => $info['sku'], 'size_eu' => $info['size_eu'], 'vat_regime' => $regime, 'quantity' => $q, 'unit_price' => $offer['price'], 'stock_type' => $type];
            }
        }
        if ($errors !== []) {
            return TransportResponse::json(400, ['stock' => $errors]);
        }
        $shipping = $immPieces > 0
            ? (WtbMapper::money($zone['first_piece']) ?? 0) + ($immPieces - 1) * (WtbMapper::money($zone['each_additional_piece']) ?? 0)
            : 0;
        $extended = $this->extendedTransport($extPieces);
        $vat = $hasOrdinary ? intdiv(($ordinaryTaxable + $shipping + $extended) * 22 + 50, 100) : 0;
        $notes = [];
        if ($hasOrdinary) {
            $notes[] = 'IVA ordinaria';
        }
        if ($hasMargin) {
            $notes[] = 'Operazione soggetta al regime del margine (art. 36 e ss. DL 41/1995): IVA non esposta in fattura e non detraibile dal cessionario.';
        }

        return [
            'currency' => 'EUR',
            'ship_to_country' => $country,
            'pieces' => $immPieces + $extPieces,
            'subtotal' => Money::toDecimal($subtotal),
            'shipping' => $immPieces > 0 ? Money::toDecimal($shipping) : '0',
            'extended_stock_transport' => Money::toDecimal($extended),
            'shipping_total' => Money::toDecimal($shipping + $extended),
            'vat' => Money::toDecimal($vat),
            'total' => Money::toDecimal($subtotal + $shipping + $extended + $vat),
            'vat_note' => implode(' · ', $notes),
            'lines' => $lines,
        ];
    }

    /** @param array<mixed> $line */
    private function resolveVariant(array $line): ?int
    {
        if (is_int($line['variant_id'] ?? null)) {
            return isset($this->variants[$line['variant_id']]) ? $line['variant_id'] : null;
        }
        if (is_string($line['sku'] ?? null) && is_string($line['size_eu'] ?? null)) {
            foreach ($this->variants as $id => $info) {
                if ($info['sku'] === $line['sku'] && $info['size_eu'] === $line['size_eu']) {
                    return $id;
                }
            }
        }

        return null;
    }

    private function extendedTransport(int $pieces): int
    {
        $ext = is_array($this->shipping['extended_stock_transport'] ?? null) ? $this->shipping['extended_stock_transport'] : [];
        $free = is_int($ext['free_from_pieces'] ?? null) ? $ext['free_from_pieces'] : null;
        if ($pieces <= 0 || ($free !== null && $pieces >= $free)) {
            return 0;
        }
        $amount = 0;
        foreach (is_array($ext['by_pieces'] ?? null) ? $ext['by_pieces'] : [] as $step) {
            if (is_array($step) && (int) $step['pieces'] <= $pieces) {
                $amount = WtbMapper::money($step['amount']) ?? 0;
            }
        }

        return $amount;
    }
}
