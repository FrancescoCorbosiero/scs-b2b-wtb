<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Support\Db;
use App\Support\Money;

/**
 * Gestione delle regole margine da /admin/margini: validazione, salvataggio, reprice immediato,
 * prodotti toccati ed esempio di prezzo (docs/04).
 */
final class MarginRuleService
{
    public function __construct(
        private readonly MarginRuleRepository $rules,
        private readonly RepriceService $reprice,
        private readonly Db $db,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $get = static fn (string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $errors = [];
        $type = $get('match_type');
        if (!in_array($type, MarginRule::MATCH_TYPES, true)) {
            $errors['match_type'] = 'Tipo di regola non valido.';
        }
        $value = $get('match_value');
        if ($value === '' || mb_strlen($value) > 2000) {
            $errors['match_value'] = 'Scrivi a cosa si applica la regola.';
        } elseif ($type === 'gender' && !in_array($value, ['men', 'women', 'kids', 'unisex'], true)) {
            $errors['match_value'] = 'Genere: men, women, kids o unisex.';
        } elseif ($type === 'size_category' && !in_array($value, ['adult', 'gs', 'ps'], true)) {
            $errors['match_value'] = 'Categoria di taglia: adult, gs o ps.';
        }
        if ($type === 'sku') {
            $value = implode(',', array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $value) ?: []))));
        }
        $regime = $get('regime');
        $stock = $get('stock_type');
        $marginType = $get('margin_type');
        $marginValue = null;
        if (!in_array($marginType, MarginSpec::TYPES, true)) {
            $errors['margin_type'] = 'Tipo di margine non valido.';
        } elseif ($marginType === 'percent') {
            $normalized = str_replace(',', '.', $get('margin_value'));
            if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $normalized) !== 1) {
                $errors['margin_value'] = 'Percentuale con al massimo due decimali, es. 7,5.';
            } else {
                $marginValue = Money::parse($normalized);
            }
        } else {
            $marginValue = Money::parseInput($get('margin_value'));
            if ($marginValue === null) {
                $errors['margin_value'] = 'Importo in euro, es. 10,00.';
            }
        }
        $priority = $get('priority') === '' ? 100 : (int) $get('priority');

        return [
            'data' => [
                'priority' => max(0, min(9999, $priority)),
                'match_type' => $type,
                'match_value' => $value,
                'regime' => in_array($regime, ['margin', 'ordinary'], true) ? $regime : null,
                'stock_type' => in_array($stock, ['immediate', 'extended'], true) ? $stock : null,
                'margin_type' => $marginType,
                'margin_value' => $marginValue ?? 0,
                'note' => mb_substr($get('note'), 0, 500),
            ],
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, string $by): int
    {
        $id = $this->rules->create($data + ['is_active' => 1], $by);
        $this->reprice->repriceAll();

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, string $by): void
    {
        $this->rules->update($id, $data, $by);
        $this->reprice->repriceAll();
    }

    public function toggle(int $id, string $by): void
    {
        $rule = $this->rules->find($id);
        if ($rule !== null) {
            $this->rules->setActive($id, !$rule->isActive, $by);
            $this->reprice->repriceAll();
        }
    }

    public function delete(int $id): void
    {
        $this->rules->delete($id);
        $this->reprice->repriceAll();
    }

    /**
     * Prodotti e offerte a cui la regola si applica davvero (dopo le precedenze), un esempio di prezzo
     * e quante offerte finiscono sotto costo.
     *
     * @return array{products: int, offers: int, below_cost: int, example: array<string, mixed>|null}
     */
    public function impact(MarginRule $rule): array
    {
        $example = $this->db->one(
            'SELECT p.sku, p.name, o.vat_regime, o.stock_type, o.cost_cents, o.sale_price_cents
             FROM offers o JOIN variants v ON v.id = o.variant_id JOIN products p ON p.id = v.product_id
             WHERE o.margin_rule_id = :r AND p.is_active = 1 ORDER BY o.available > 0 DESC, p.name LIMIT 1',
            ['r' => $rule->id],
        );

        return [
            'products' => $this->db->int('SELECT COUNT(DISTINCT v.product_id) FROM offers o JOIN variants v ON v.id = o.variant_id WHERE o.margin_rule_id = :r', ['r' => $rule->id]),
            'offers' => $this->db->int('SELECT COUNT(*) FROM offers WHERE margin_rule_id = :r', ['r' => $rule->id]),
            'below_cost' => $this->db->int('SELECT COUNT(*) FROM offers WHERE margin_rule_id = :r AND sale_price_cents < cost_cents', ['r' => $rule->id]),
            'example' => $example,
        ];
    }

    /** Valore della regola come testo per il modulo. */
    public static function formatValue(string $type, int $value): string
    {
        return $type === 'percent' ? str_replace('.', ',', rtrim(rtrim(Money::toDecimal($value), '0'), '.')) : Money::formatInput($value);
    }
}
