<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Catalog\ProductAggregates;
use App\Settings\SettingsService;
use App\Support\Clock;
use App\Support\Db;

/**
 * Ricalcola i prezzi di vendita precalcolati delle offerte (dopo ogni sync e ogni modifica delle regole)
 * e poi gli aggregati dei prodotti (prezzo "da", disponibilità).
 */
final class RepriceService
{
    public function __construct(
        private readonly Db $db,
        private readonly SettingsService $settings,
        private readonly MarginResolver $resolver,
        private readonly ProductAggregates $aggregates,
        private readonly Clock $clock,
    ) {
    }

    public function repriceAll(): int
    {
        return $this->reprice(null);
    }

    /** @param list<int> $productIds */
    public function repriceProducts(array $productIds): int
    {
        return $productIds === [] ? 0 : $this->reprice(array_values(array_unique($productIds)));
    }

    /** @param list<int>|null $productIds */
    private function reprice(?array $productIds): int
    {
        $rounding = $this->settings->string('prices.rounding');
        $resolver = $this->resolver->withRules();
        $where = '';
        $params = [];
        if ($productIds !== null) {
            $placeholders = [];
            foreach ($productIds as $i => $id) {
                $placeholders[] = ':p' . $i;
                $params['p' . $i] = $id;
            }
            $where = 'WHERE p.id IN (' . implode(',', $placeholders) . ')';
        }
        $rows = $this->db->all(
            'SELECT o.id, o.cost_cents, o.sale_price_cents, o.margin_rule_id, o.vat_regime, o.stock_type,
                    p.id AS product_id, p.sku, p.name, p.brand, p.line, p.gender, p.category_path, p.size_category
             FROM offers o JOIN variants v ON v.id = o.variant_id JOIN products p ON p.id = v.product_id ' . $where,
            $params,
        );
        $changed = 0;
        $this->db->transaction(function (Db $db) use ($rows, $rounding, $resolver, &$changed): void {
            $now = $this->clock->iso();
            foreach ($rows as $row) {
                $spec = $resolver->resolve($row, (string) $row['vat_regime'], (string) $row['stock_type']);
                $price = PricingService::salePrice((int) $row['cost_cents'], $spec, $rounding);
                if ($price !== ($row['sale_price_cents'] === null ? null : (int) $row['sale_price_cents']) || $spec->ruleId !== ($row['margin_rule_id'] === null ? null : (int) $row['margin_rule_id'])) {
                    $db->run(
                        'UPDATE offers SET sale_price_cents = :price, margin_rule_id = :rule, updated_at = :now WHERE id = :id',
                        ['price' => $price, 'rule' => $spec->ruleId, 'now' => $now, 'id' => $row['id']],
                    );
                    $changed++;
                }
            }
        });
        $this->aggregates->recompute($productIds ?? null);

        return $changed;
    }
}
