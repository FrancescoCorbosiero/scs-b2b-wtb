<?php

declare(strict_types=1);

namespace App\Orders;

use App\Settings\SettingsService;
use App\Shipping\CountryRepository;
use App\Supplier\Exception\SupplierException;
use App\Supplier\Exception\SupplierRejectedException;
use App\Supplier\Model\SupplierOrderRequest;
use App\Supplier\SupplierOrders;
use App\Support\Clock;
use App\Support\Db;
use App\Support\Translator;
use Psr\Log\LoggerInterface;

/**
 * Ordine composto dall'admin (D-019): serve a provare l'ordine al fornitore senza il lato cliente.
 * Righe dalla bozza in sessione, indirizzo di spedizione scritto a mano, preventivo del fornitore per costi e
 * disponibilità; poi è un ordine come gli altri (stessa tabella, stesso "Crea ordine al fornitore", stessi webhook)
 * con origine "admin": niente email al cliente, niente pro-forma.
 */
final class AdminOrderService
{
    /** Utente tecnico a cui si intestano gli ordini dell'admin: inattivo e senza password, non può entrare. */
    public const INTERNAL_EMAIL = 'ordini-interni@scs-b2b.invalid';

    public const SEARCH_LIMIT = 15;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly SupplierOrders $supplier,
        private readonly CountryRepository $countries,
        private readonly SettingsService $settings,
        private readonly Translator $t,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Prodotti attivi per SKU o nome, con le unità ordinabili (variante × regime) e le fasce: disponibilità,
     * costo (RISERVATO, solo admin), prezzo di vendita, tempi di partenza.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $products = $this->db->all(
            "SELECT id, sku, name, brand FROM products
             WHERE is_active = 1 AND (sku LIKE :q ESCAPE '\\' OR search_text LIKE :ql ESCAPE '\\')
             ORDER BY total_available = 0, name COLLATE NOCASE LIMIT " . self::SEARCH_LIMIT,
            ['q' => '%' . addcslashes($q, '%_\\') . '%', 'ql' => '%' . addcslashes(mb_strtolower($q), '%_\\') . '%'],
        );
        foreach ($products as &$product) {
            $units = [];
            foreach ($this->db->all(
                'SELECT v.id AS variant_id, v.wtb_variant_id, v.size_eu, v.size_us, v.size_uk, o.vat_regime, o.stock_type, o.available,
                        o.cost_cents, o.sale_price_cents, o.ships_in_days
                 FROM variants v JOIN offers o ON o.variant_id = v.id
                 WHERE v.product_id = :p AND v.is_active = 1 AND o.available > 0
                 ORDER BY v.size_sort, v.id, o.vat_regime, o.stock_type',
                ['p' => $product['id']],
            ) as $row) {
                $key = Cart::key((int) $row['variant_id'], (string) $row['vat_regime']);
                $units[$key] ??= [
                    'key' => $key, 'wtb_variant_id' => (int) $row['wtb_variant_id'], 'size_eu' => (string) $row['size_eu'],
                    'size_us' => (string) $row['size_us'], 'regime' => (string) $row['vat_regime'], 'tiers' => [], 'available' => 0,
                ];
                $units[$key]['tiers'][] = $row;
                $units[$key]['available'] += (int) $row['available'];
            }
            $product['units'] = array_values($units);
        }
        unset($product);

        return $products;
    }

    /** Riepilogo della bozza con prezzi di vendita e costi correnti (solo admin). @param array<string, int> $items @return array<string, mixed> */
    public function draft(array $items, string $country): array
    {
        $view = $this->carts->build($items, $country, $country);
        $cost = 0;
        foreach ($view['lines'] as $line) {
            foreach ($line['unit']['split']['lines'] as $l) {
                $cost += $l['quantity'] * (int) $this->db->value(
                    'SELECT cost_cents FROM offers WHERE variant_id = :v AND vat_regime = :r AND stock_type = :s',
                    ['v' => $line['unit']['variant_id'], 'r' => $line['unit']['regime'], 's' => $l['stock_type']],
                );
            }
        }

        return $view + ['expected_cost_cents' => $cost];
    }

    /**
     * Valida l'indirizzo, chiede il preventivo al fornitore e salva l'ordine (stato "pending", origine "admin").
     * L'ordine al fornitore NON parte da qui: si crea dalla pagina dell'ordine, con la conferma della modalità.
     *
     * @param array<string, mixed> $input
     * @return array{status: 'created'|'invalid'|'empty'|'changed'|'rejected', order_id?: int, errors?: array<string, string>, messages?: list<string>}
     */
    public function create(Cart $cart, array $input, string $by): array
    {
        $form = $this->validate($input);
        if ($form['errors'] !== []) {
            return ['status' => 'invalid', 'errors' => $form['errors']];
        }
        $data = $form['data'];
        $country = $data['ship_country'];
        $view = $this->carts->build($cart->items(), $country, $country);
        if ($view['empty']) {
            return ['status' => 'empty'];
        }
        if ($view['notices'] !== []) {
            $cart->replace($view['items']);

            return ['status' => 'changed', 'messages' => [$this->t->get('admin.new_order.changed_local')]];
        }

        // Preventivo del fornitore: costi veri e ripartizione pronta consegna / stock esteso.
        $request = new SupplierOrderRequest(
            'ADMIN-DRAFT',
            array_map(static fn (array $l): array => [
                'variant_id' => (int) $l['unit']['wtb_variant_id'],
                'vat_regime' => (string) $l['unit']['regime'],
                'quantity' => (int) $l['unit']['quantity'],
            ], $view['lines']),
            ['country' => $country],
        );
        $quote = null;
        $costs = [];
        $unverified = false;
        try {
            $quote = $this->supplier->quote($request);
        } catch (SupplierRejectedException $e) {
            $detail = implode(' · ', $e->hasStockIssues() ? $e->stockIssues() : [$e->getMessage()]);

            return ['status' => 'rejected', 'messages' => [$this->t->get('admin.new_order.rejected', ['detail' => $detail])]];
        } catch (SupplierException $e) {
            $this->logger->warning('Ordine admin: preventivo non disponibile, salvato col controllo locale', ['error' => $e->getMessage()]);
            $unverified = true;
        }
        if ($quote !== null) {
            $result = QuoteReconciler::reconcile($view['lines'], $quote, $this->settings->bool('catalog.sell_extended'));
            if ($result['reduce'] !== []) {
                $items = $cart->items();
                foreach ($result['reduce'] as $key => $max) {
                    $items[$key] = $max;
                }
                $cart->replace($items);

                return ['status' => 'changed', 'messages' => [$this->t->get('admin.new_order.changed_quote')]];
            }
            $view = $this->carts->build($cart->items(), $country, $country, $result['split']);
            $costs = $result['costs'];
        }

        $customer = $this->internalUser() + [
            'company' => $data['ship_company'] !== '' ? $data['ship_company'] : $data['ship_name'],
            'vat_number' => '',
            'address_street' => $data['ship_street'],
            'address_city' => $data['ship_city'],
            'address_zip' => $data['ship_zip'],
            'address_province' => $data['ship_province'],
            'country_code' => $country,
        ];
        $checkout = [
            'contact_name' => $data['ship_name'],
            'email' => $data['email'],
            'phone' => $data['ship_phone'],
            'ship_to_mode' => 'billing',
            'notes' => '',
        ];
        $orderId = $this->orders->create(
            $customer, $checkout, $view, $quote, $costs, $unverified, 'admin-' . bin2hex(random_bytes(12)),
            '', 'admin: ' . $by, $this->supplier->mode(), 'it', 'admin',
        );
        $this->db->update('orders', ['admin_notes' => $data['admin_notes']], 'id = :id', ['id' => $orderId]);
        $cart->clear();
        $this->logger->info('Ordine composto dall\'admin', ['order' => $orderId, 'by' => $by, 'mode' => $this->supplier->mode(), 'unverified' => $unverified]);

        return ['status' => 'created', 'order_id' => $orderId, 'messages' => $unverified ? [$this->t->get('admin.new_order.unverified')] : []];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, string>, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $get = static fn (string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $data = [];
        foreach (['ship_name', 'ship_company', 'ship_street', 'ship_city', 'ship_zip', 'ship_province', 'ship_phone'] as $field) {
            $data[$field] = mb_substr($get($field), 0, 150);
        }
        $data['ship_country'] = strtoupper($get('ship_country'));
        $data['ship_province'] = strtoupper($data['ship_province']);
        $data['email'] = strtolower($get('email'));
        $data['admin_notes'] = mb_substr($get('admin_notes'), 0, 1000);
        $errors = [];
        foreach (['ship_name', 'ship_street', 'ship_city', 'ship_zip'] as $field) {
            if ($data[$field] === '') {
                $errors[$field] = 'validation.required';
            }
        }
        if (!$this->countries->isServed($data['ship_country'])) {
            $errors['ship_country'] = 'validation.country';
        }
        if ($data['ship_country'] === 'IT' && preg_match('/^\d{5}$/', $data['ship_zip']) !== 1) {
            $errors['ship_zip'] = 'validation.zip_it';
        }
        if ($data['ship_country'] === 'IT' && preg_match('/^[A-Z]{2}$/', $data['ship_province']) !== 1) {
            $errors['ship_province'] = 'validation.province';
        }
        if (preg_match('/^\+?[0-9 ()\/.\-]{6,25}$/', $data['ship_phone']) !== 1) {
            $errors['ship_phone'] = 'validation.phone';
        }
        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'validation.email';
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /** Email proposta nel modulo: il primo indirizzo admin delle impostazioni. */
    public function defaultEmail(): string
    {
        return $this->settings->list('company.admin_emails')[0] ?? '';
    }

    /** @return array{id: int} */
    private function internalUser(): array
    {
        $id = $this->db->value('SELECT id FROM users WHERE email = :e', ['e' => self::INTERNAL_EMAIL]);
        if ($id === null) {
            $now = $this->clock->iso();
            $id = $this->db->insert('users', [
                'email' => self::INTERNAL_EMAIL,
                'password_hash' => null,
                'name' => 'Ordini interni (admin)',
                'company' => 'Ordini interni (admin)',
                'vat_number' => '',
                'country_code' => 'IT',
                'is_active' => 0,
                'admin_notes' => 'Utente tecnico degli ordini composti dall\'admin (D-019): non può entrare.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return ['id' => (int) $id];
    }
}
