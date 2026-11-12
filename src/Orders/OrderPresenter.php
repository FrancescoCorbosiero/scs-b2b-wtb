<?php

declare(strict_types=1);

namespace App\Orders;

use App\Tax\TaxPolicy;

/**
 * Ordine per le uscite verso il cliente (pagine, email, PDF): SOLO campi ammessi. Costi, preventivo del
 * fornitore e note interne non passano mai di qui (regola d'oro 1).
 */
final class OrderPresenter
{
    private const ORDER_FIELDS = [
        'id', 'status', 'locale', 'company', 'vat_number', 'contact_name', 'email', 'phone', 'billing_street', 'billing_city',
        'billing_zip', 'billing_province', 'billing_country', 'ship_to_mode', 'ship_name', 'ship_company', 'ship_street',
        'ship_city', 'ship_zip', 'ship_province', 'ship_country', 'ship_phone', 'shipping_zone', 'vat_scheme', 'vat_rate_bp',
        'shipping_regime', 'pairs', 'subtotal_margin_cents', 'subtotal_ordinary_cents', 'shipping_cents', 'vat_cents',
        'total_cents', 'departure_days', 'delivery_days', 'receipt_number', 'notes', 'created_at', 'confirmed_at', 'shipped_at',
        'cancelled_at',
    ];

    private const LINE_FIELDS = [
        'sku', 'name', 'brand', 'size_eu', 'size_us', 'size_uk', 'ean', 'vat_regime', 'qty_immediate',
        'unit_price_immediate_cents', 'qty_extended', 'unit_price_extended_cents', 'line_total_cents', 'ships_in_days',
    ];

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public static function forCustomer(array $order, array $lines): array
    {
        $safe = array_intersect_key($order, array_flip(self::ORDER_FIELDS));
        $safeLines = array_map(static fn (array $l): array => array_intersect_key($l, array_flip(self::LINE_FIELDS)), $lines);
        $safe['lines_margin'] = array_values(array_filter($safeLines, static fn (array $l): bool => $l['vat_regime'] === 'margin'));
        $safe['lines_ordinary'] = array_values(array_filter($safeLines, static fn (array $l): bool => $l['vat_regime'] === 'ordinary'));
        $safe['legends'] = TaxPolicy::calculate(
            (string) $order['vat_scheme'],
            intdiv((int) $order['vat_rate_bp'], 100),
            (string) $order['shipping_regime'],
            (int) $order['subtotal_margin_cents'],
            (int) $order['subtotal_ordinary_cents'],
            (int) $order['shipping_cents'],
        )->legends;

        return $safe;
    }
}
