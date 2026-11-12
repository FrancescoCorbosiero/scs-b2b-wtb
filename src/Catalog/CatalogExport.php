<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Support\Money;
use App\Support\Translator;
use App\Support\Xlsx;
use App\Support\Db;

/**
 * Export Excel del risultato filtrato (docs/07): una riga per taglia × regime con paia e prezzo per fascia.
 * Mai costi, mai il nome del fornitore (nemmeno nel nome del file o nei metadati).
 */
final class CatalogExport
{
    public function __construct(
        private readonly CatalogQuery $query,
        private readonly Db $db,
        private readonly ProductAggregates $aggregates,
        private readonly Translator $t,
    ) {
    }

    public function build(CatalogFilters $filters, string $siteName): string
    {
        $rows = [[
            'SKU', $this->t->get('export.name'), $this->t->get('export.brand'), $this->t->get('export.line'), $this->t->get('export.gender'),
            $this->t->get('export.size_eu'), $this->t->get('export.size_us'), $this->t->get('export.size_uk'), 'EAN', $this->t->get('export.regime'),
            $this->t->get('export.qty_immediate'), $this->t->get('export.price_immediate'), $this->t->get('export.qty_extended'),
            $this->t->get('export.price_extended'), $this->t->get('export.ships'),
        ]];
        $sellable = $this->aggregates->sellableCondition();
        foreach ($this->query->all($filters) as $product) {
            $offers = $this->db->all(
                'SELECT v.id, v.size_eu, v.size_us, v.size_uk, v.ean, o.vat_regime, o.stock_type, o.available, o.sale_price_cents, o.ships_in_days
                 FROM variants v JOIN offers o ON o.variant_id = v.id
                 WHERE v.product_id = :p AND ' . $sellable . ' ORDER BY v.size_sort, v.id, o.vat_regime',
                ['p' => $product['id']],
            );
            $units = [];
            foreach ($offers as $o) {
                $key = $o['id'] . '|' . $o['vat_regime'];
                $units[$key] ??= ['v' => $o, 'immediate' => null, 'extended' => null];
                $units[$key][(string) $o['stock_type']] = $o;
            }
            foreach ($units as $unit) {
                $v = $unit['v'];
                $imm = $unit['immediate'];
                $ext = $unit['extended'];
                $ships = array_filter([$imm['ships_in_days'] ?? '', $ext['ships_in_days'] ?? '']);
                $rows[] = [
                    (string) $product['sku'], (string) $product['name'], (string) $product['brand'], (string) $product['line'],
                    $this->t->get('catalog.gender.' . ($product['gender'] === '' ? 'none' : $product['gender'])),
                    (string) $v['size_eu'], (string) $v['size_us'], (string) $v['size_uk'], (string) $v['ean'],
                    $this->t->get('catalog.regime.' . $v['vat_regime']),
                    $imm === null ? 0 : (int) $imm['available'],
                    $imm === null ? null : Money::toExportNumber((int) $imm['sale_price_cents']),
                    $ext === null ? 0 : (int) $ext['available'],
                    $ext === null ? null : Money::toExportNumber((int) $ext['sale_price_cents']),
                    implode(' / ', $ships),
                ];
            }
        }

        return Xlsx::build($this->t->get('export.sheet'), $rows, $siteName);
    }
}
