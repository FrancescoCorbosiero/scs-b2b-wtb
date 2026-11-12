<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsService;
use App\Support\Db;

/**
 * Valori denormalizzati dei prodotti per card e filtri: paia vendibili, prezzo "da", pronta consegna.
 * "Vendibile" = variante attiva, disponibile, regime in vendita (catalog.regimes) e tipo di stock in
 * vendita (catalog.sell_extended).
 */
final class ProductAggregates
{
    public function __construct(
        private readonly Db $db,
        private readonly SettingsService $settings,
    ) {
    }

    /** Condizione SQL sulle offerte vendibili (alias "o" offerte, "v" varianti). Valori dal codice, non dall'utente. */
    public function sellableCondition(): string
    {
        $regimes = array_values(array_intersect(['margin', 'ordinary'], explode(',', $this->settings->string('catalog.regimes'))));
        $in = $regimes === [] ? "''" : "'" . implode("','", $regimes) . "'";
        $stock = $this->settings->bool('catalog.sell_extended') ? '' : " AND o.stock_type = 'immediate'";

        return 'v.is_active = 1 AND o.available > 0 AND o.sale_price_cents IS NOT NULL AND o.vat_regime IN (' . $in . ')' . $stock;
    }

    /** @param list<int>|null $productIds null = tutti */
    public function recompute(?array $productIds = null): void
    {
        $cond = $this->sellableCondition();
        $where = '';
        $params = [];
        if ($productIds !== null) {
            if ($productIds === []) {
                return;
            }
            $placeholders = [];
            foreach ($productIds as $i => $id) {
                $placeholders[] = ':p' . $i;
                $params['p' . $i] = $id;
            }
            $where = ' WHERE id IN (' . implode(',', $placeholders) . ')';
        }
        $sub = 'FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.product_id = products.id AND ' . $cond;
        $this->db->run(
            'UPDATE products SET
                total_available = (SELECT COALESCE(SUM(o.available), 0) ' . $sub . '),
                has_immediate = EXISTS (SELECT 1 ' . $sub . " AND o.stock_type = 'immediate'),
                min_price_cents = (SELECT MIN(o.sale_price_cents) " . $sub . '),
                min_price_regime = (SELECT o.vat_regime ' . $sub . ' ORDER BY o.sale_price_cents, o.vat_regime LIMIT 1)' . $where,
            $params,
        );
    }
}
