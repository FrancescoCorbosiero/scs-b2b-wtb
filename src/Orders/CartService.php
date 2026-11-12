<?php

declare(strict_types=1);

namespace App\Orders;

use App\Catalog\CatalogPresenter;
use App\Catalog\ImageService;
use App\Catalog\ProductAggregates;
use App\Pricing\Tier;
use App\Pricing\TierCalculator;
use App\Settings\SettingsService;
use App\Shipping\CountryRepository;
use App\Shipping\DeliveryEstimate;
use App\Shipping\ShippingCalculator;
use App\Shipping\ShippingZone;
use App\Shipping\ZoneRepository;
use App\Support\Db;
use App\Support\SystemFlags;
use App\Tax\TaxPolicy;

/**
 * Riepilogo autorevole del carrello (docs/07 § Carrello): ricalcolato ogni volta dalle offerte correnti.
 * Solo prezzi di vendita: i costi non entrano mai in questo modello (regola d'oro 1).
 */
final class CartService
{
    public function __construct(
        private readonly Db $db,
        private readonly SettingsService $settings,
        private readonly ProductAggregates $aggregates,
        private readonly CountryRepository $countries,
        private readonly ZoneRepository $zones,
        private readonly SystemFlags $flags,
    ) {
    }

    /** Ordine minimo: il maggiore tra orders.min_items e il minimo del fornitore (profilo Wholesaler). */
    public function minItems(): int
    {
        $supplier = $this->flags->getJson('supplier.profile');
        $supplierMin = is_int($supplier['min_order'] ?? null) ? $supplier['min_order'] : 0;

        return max($this->settings->int('orders.min_items'), $supplierMin);
    }

    /**
     * @param array<string, int> $items chiave unità => paia
     * @param array<string, array{immediate: int, extended: int}> $splitOverride ripartizione confermata dal preventivo
     * @return array<string, mixed>
     */
    public function build(array $items, string $billingCountry, ?string $destinationCountry, array $splitOverride = []): array
    {
        $productIds = [];
        $requested = [];
        foreach ($items as $key => $qty) {
            $parsed = Cart::parseKey($key);
            if ($parsed !== null) {
                $requested[$key] = $qty;
            }
        }
        $variantIds = array_values(array_unique(array_map(static fn (string $k): int => (int) Cart::parseKey($k)[0], array_keys($requested))));
        if ($variantIds !== []) {
            [$in, $params] = self::in($variantIds, 'v');
            $productIds = array_map('intval', $this->db->column('SELECT DISTINCT product_id FROM variants WHERE id IN (' . $in . ')', $params));
        }
        $products = $this->loadProducts($productIds);
        $notices = [];
        $lines = [];
        $adjusted = [];
        $margin = 0;
        $ordinary = 0;
        $pairs = 0;
        $ships = [];
        foreach ($products as &$product) {
            foreach ($product['units'] as &$unit) {
                $key = $unit['key'];
                $want = $requested[$key] ?? 0;
                unset($requested[$key]);
                $qty = min($want, $unit['max']);
                if ($want > $unit['max']) {
                    $notices[] = ['type' => 'reduced', 'sku' => $product['sku'], 'name' => $product['name'], 'size' => $unit['size_eu'], 'regime' => $unit['regime'], 'from' => $want, 'to' => $qty];
                }
                $unit['quantity'] = $qty;
                $tiers = $unit['tier_objects'];
                $split = TierCalculator::split($tiers, $qty);
                if (isset($splitOverride[$key]) && $splitOverride[$key]['immediate'] + $splitOverride[$key]['extended'] === $qty) {
                    $split = self::forcedSplit($tiers, $splitOverride[$key]['immediate'], $splitOverride[$key]['extended']);
                }
                unset($unit['tier_objects']);
                $unit['split'] = $split;
                if ($qty > 0) {
                    $adjusted[$key] = $qty;
                    $pairs += $qty;
                    $unit['regime'] === 'margin' ? $margin += $split['total_cents'] : $ordinary += $split['total_cents'];
                    foreach ($split['lines'] as $l) {
                        $ships[] = $l['ships_in_days'];
                    }
                    $lines[] = ['product' => $product, 'unit' => $unit];
                }
            }
            unset($unit);
            $product['quantity'] = array_sum(array_map(static fn (array $u): int => $u['quantity'], $product['units']));
            $product['total_cents'] = array_sum(array_map(static fn (array $u): int => $u['split']['total_cents'], $product['units']));
        }
        unset($product);
        foreach ($requested as $key => $qty) {
            $info = $this->db->one(
                'SELECT p.sku, p.name, v.size_eu FROM variants v JOIN products p ON p.id = v.product_id WHERE v.id = :v',
                ['v' => (int) (Cart::parseKey($key)[0] ?? 0)],
            );
            $notices[] = [
                'type' => 'removed', 'sku' => (string) ($info['sku'] ?? ''), 'name' => (string) ($info['name'] ?? ''),
                'size' => (string) ($info['size_eu'] ?? ''), 'regime' => (string) (Cart::parseKey($key)[1] ?? ''), 'from' => $qty, 'to' => 0,
            ];
        }
        $products = array_values(array_filter($products, static fn (array $p): bool => $p['quantity'] > 0 || $p['in_cart']));

        $destination = strtoupper($destinationCountry ?? $billingCountry);
        if (!$this->countries->isServed($destination)) {
            $destination = $this->countries->isServed($billingCountry) ? strtoupper($billingCountry) : 'IT';
        }
        $zoneCode = $this->countries->zoneOf($destination) ?? 'IT';
        $zone = $this->zones->toZone($zoneCode);
        $shipping = ShippingCalculator::fee($zone->feeCents, $zone->freeFromItems, $pairs);
        $shippingRegime = $this->settings->string('tax.shipping_regime');
        $tax = TaxPolicy::calculate(
            TaxPolicy::scheme($billingCountry, $this->countries->isEu($billingCountry)),
            $this->settings->int('tax.vat_rate_it'),
            $shippingRegime,
            $margin,
            $ordinary,
            $shipping,
        );
        $departure = DeliveryEstimate::departure($ships);
        $min = $this->minItems();

        return [
            'products' => $products,
            'lines' => $lines,
            'items' => $adjusted,
            'notices' => $notices,
            'pairs' => $pairs,
            'min_items' => $min,
            'meets_minimum' => $pairs >= $min,
            'destination' => $destination,
            'zone' => $zone,
            'shipping_cents' => $shipping,
            'free_shipping_from' => $zone->freeFromItems,
            'tax' => $tax,
            'shipping_regime' => $shippingRegime,
            'departure' => $departure,
            'delivery' => $departure === null ? null : DeliveryEstimate::delivery($departure, $zone->transitMin, $zone->transitMax),
            'empty' => $pairs === 0,
        ];
    }

    /** Totali compatti per il JSON del carrello (nessun costo). @param array<string, mixed> $view @return array<string, mixed> */
    public static function summary(array $view): array
    {
        /** @var \App\Tax\TaxResult $tax */
        $tax = $view['tax'];

        return [
            'pairs' => $view['pairs'],
            'min_items' => $view['min_items'],
            'meets_minimum' => $view['meets_minimum'],
            'subtotal_margin_cents' => $tax->subtotalMarginCents,
            'subtotal_ordinary_cents' => $tax->subtotalOrdinaryCents,
            'shipping_cents' => $tax->shippingCents,
            'vat_cents' => $tax->vatCents,
            'total_cents' => $tax->totalCents,
            'destination' => $view['destination'],
        ];
    }

    /**
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    private function loadProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        [$in, $params] = self::in($productIds, 'p');
        $sellable = $this->aggregates->sellableCondition();
        $rows = $this->db->all(
            'SELECT p.id AS product_id, p.sku, p.name, p.brand, p.line, v.id AS variant_id, v.wtb_variant_id, v.size_eu, v.size_us, v.size_uk, v.ean,
                    o.vat_regime, o.stock_type, o.available, o.sale_price_cents, o.ships_in_days
             FROM products p JOIN variants v ON v.product_id = p.id JOIN offers o ON o.variant_id = v.id
             WHERE p.id IN (' . $in . ') AND p.is_active = 1 AND ' . $sellable . '
             ORDER BY p.name COLLATE NOCASE, p.id, v.size_sort, v.id, o.vat_regime',
            $params,
        );
        $images = [];
        foreach ($this->db->all('SELECT product_id, source_url FROM product_images WHERE position = 0 AND product_id IN (' . $in . ')', $params) as $img) {
            $images[(int) $img['product_id']] = ImageService::publicUrl((int) $img['product_id'], 0, (string) $img['source_url'], 400);
        }
        $products = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $products[$pid] ??= [
                'id' => $pid, 'sku' => (string) $row['sku'], 'name' => (string) $row['name'], 'brand' => (string) $row['brand'],
                'image' => $images[$pid] ?? null, 'units' => [], 'in_cart' => true,
            ];
            $key = Cart::key((int) $row['variant_id'], (string) $row['vat_regime']);
            $products[$pid]['units'][$key] ??= [
                'key' => $key, 'variant_id' => (int) $row['variant_id'], 'wtb_variant_id' => (int) $row['wtb_variant_id'],
                'regime' => (string) $row['vat_regime'], 'size_eu' => (string) $row['size_eu'], 'size_us' => (string) $row['size_us'],
                'size_uk' => (string) $row['size_uk'], 'ean' => (string) $row['ean'], 'tiers' => [], 'tier_objects' => [], 'max' => 0,
            ];
            $tier = [
                'stock_type' => (string) $row['stock_type'],
                'available' => (int) $row['available'],
                'price' => (int) $row['sale_price_cents'],
                'ships_in_days' => (string) $row['ships_in_days'],
            ];
            $products[$pid]['units'][$key]['tiers'][] = $tier;
            $products[$pid]['units'][$key]['tier_objects'][] = new Tier($tier['stock_type'], $tier['available'], $tier['price'], $tier['ships_in_days']);
            $products[$pid]['units'][$key]['max'] += (int) $row['available'];
        }
        foreach ($products as &$product) {
            foreach ($product['units'] as &$unit) {
                usort($unit['tiers'], static fn (array $a, array $b): int => ($a['stock_type'] === 'immediate' ? 0 : 1) <=> ($b['stock_type'] === 'immediate' ? 0 : 1));
                $unit['size'] = CatalogPresenter::sizeLabel($unit, 'eu');
            }
            unset($unit);
            $product['units'] = array_values($product['units']);
        }
        unset($product);

        return array_values($products);
    }

    /**
     * Ripartizione imposta dal preventivo del fornitore (che può dare più paia in pronta consegna di quelle
     * indicate da "available"): i prezzi restano quelli di vendita della fascia.
     *
     * @param list<Tier> $tiers
     * @return array{lines: list<array{stock_type: string, quantity: int, unit_price_cents: int, line_total_cents: int, ships_in_days: string}>, quantity: int, total_cents: int, max: int}
     */
    public static function forcedSplit(array $tiers, int $immediate, int $extended): array
    {
        $byType = [];
        foreach ($tiers as $tier) {
            $byType[$tier->stockType] = $tier;
        }
        $lines = [];
        $total = 0;
        foreach (['immediate' => $immediate, 'extended' => $extended] as $type => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $tier = $byType[$type] ?? null;
            $price = $tier === null ? 0 : ($tier->unitPriceCents ?? 0);
            $lines[] = ['stock_type' => $type, 'quantity' => $qty, 'unit_price_cents' => $price, 'line_total_cents' => $price * $qty, 'ships_in_days' => $tier === null ? '' : $tier->shipsInDays];
            $total += $price * $qty;
        }

        return ['lines' => $lines, 'quantity' => $immediate + $extended, 'total_cents' => $total, 'max' => array_sum(array_map(static fn (Tier $t): int => $t->available, $tiers))];
    }

    /** @param list<int> $ids @return array{0: string, 1: array<string, int>} */
    private static function in(array $ids, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':' . $prefix . $i;
            $params[$prefix . $i] = $id;
        }

        return [implode(',', $placeholders), $params];
    }

    /** Zona di un paese (per i selettori). */
    public function zoneFor(string $country): ?ShippingZone
    {
        $code = $this->countries->zoneOf($country);

        return $code === null ? null : $this->zones->toZone($code);
    }
}
