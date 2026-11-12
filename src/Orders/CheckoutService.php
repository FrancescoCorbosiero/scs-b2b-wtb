<?php

declare(strict_types=1);

namespace App\Orders;

use App\Settings\SettingsService;
use App\Shipping\CountryRepository;
use App\Supplier\Exception\SupplierException;
use App\Supplier\Exception\SupplierRejectedException;
use App\Supplier\Model\SupplierOrderRequest;
use App\Supplier\Model\SupplierQuote;
use App\Supplier\SupplierOrders;
use App\Support\Clock;
use App\Support\Db;
use App\Support\RateLimiter;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Richiesta d'ordine (docs/06 § Checkout): controllo locale, preventivo del fornitore, salvataggio, email.
 * Invio idempotente con un token per form; mai prezzi né note del cliente verso il fornitore.
 */
final class CheckoutService
{
    public const TOKEN = 'checkout_token';
    public const SPLIT = 'checkout_split';
    public const DONE = 'checkout_done';
    public const MAX_PER_HOUR = 3;

    public function __construct(
        private readonly CartService $carts,
        private readonly SupplierOrders $supplier,
        private readonly OrderService $orders,
        private readonly SettingsService $settings,
        private readonly CountryRepository $countries,
        private readonly RateLimiter $limiter,
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function token(Session $session): string
    {
        $token = $session->get(self::TOKEN);
        if (!is_string($token) || strlen($token) !== 32) {
            $token = bin2hex(random_bytes(16));
            $session->set(self::TOKEN, $token);
        }

        return $token;
    }

    /** Ripartizione confermata dal preventivo, valida solo per lo stesso carrello. @return array<string, array{immediate: int, extended: int}> */
    public function splitOverride(Session $session, Cart $cart): array
    {
        $data = $session->get(self::SPLIT);

        return is_array($data) && ($data['hash'] ?? null) === $cart->hash() && is_array($data['split'] ?? null) ? $data['split'] : [];
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $input
     * @return array{status: string, order_id?: int, errors?: array<string, string>, notices?: list<array<string, mixed>>}
     */
    public function submit(Session $session, Cart $cart, array $customer, array $input, string $ip, string $userAgent, string $locale): array
    {
        $token = is_string($input['checkout_token'] ?? null) ? $input['checkout_token'] : '';
        $done = $session->get(self::DONE, []);
        if (is_array($done) && isset($done[$token]) && is_int($done[$token])) {
            return ['status' => 'already', 'order_id' => $done[$token]];
        }
        if ($token === '' || !hash_equals($this->token($session), $token)) {
            return ['status' => 'expired'];
        }
        if (is_string($input['website'] ?? null) && $input['website'] !== '') {
            return ['status' => 'invalid', 'errors' => []];
        }
        $userId = (int) $customer['id'];
        if ($this->limiter->tooMany('checkout_user', (string) $userId, self::MAX_PER_HOUR, 3600) || $this->limiter->tooMany('checkout_ip', $ip, self::MAX_PER_HOUR, 3600)) {
            return ['status' => 'limited'];
        }
        $form = $this->validate($input, $customer);
        if ($form['errors'] !== []) {
            return ['status' => 'invalid', 'errors' => $form['errors']];
        }
        $data = $form['data'];
        $destination = $data['ship_to_mode'] === 'other' ? $data['ship_country'] : (string) $customer['country_code'];
        $override = $this->splitOverride($session, $cart);
        $view = $this->carts->build($cart->items(), (string) $customer['country_code'], $destination, $override);

        // 1. Controllo locale.
        if ($view['empty']) {
            return ['status' => 'empty'];
        }
        if ($view['notices'] !== []) {
            $cart->replace($view['items']);

            return ['status' => 'changed', 'notices' => $view['notices']];
        }
        if (!$view['meets_minimum']) {
            return ['status' => 'minimum'];
        }

        // 2. Preventivo del fornitore.
        $quote = null;
        $costs = [];
        $unverified = false;
        $request = new SupplierOrderRequest(
            'CHECKOUT',
            array_map(static fn (array $l): array => [
                'variant_id' => (int) $l['unit']['wtb_variant_id'],
                'vat_regime' => (string) $l['unit']['regime'],
                'quantity' => (int) $l['unit']['quantity'],
            ], $view['lines']),
            ['country' => $destination],
        );
        try {
            $quote = $this->supplier->quote($request, true);
        } catch (SupplierRejectedException $e) {
            if (!$e->hasStockIssues()) {
                $this->logger->error('Checkout: preventivo rifiutato dal fornitore', ['error' => $e->getMessage(), 'user' => $userId]);

                return ['status' => 'error'];
            }
            $available = QuoteReconciler::parseStockIssues($e->stockIssues());
            $notices = $this->reduce($cart, $view, static function (array $product, array $unit) use ($available): ?int {
                $key = $product['sku'] . '|' . $unit['size_eu'] . '|' . $unit['regime'];

                return $available[$key] ?? null;
            });

            return ['status' => 'changed', 'notices' => $notices];
        } catch (SupplierException $e) {
            // Fornitore irraggiungibile: si accetta col solo controllo locale, l'admin lo vede (quote_unverified).
            $this->logger->warning('Checkout: preventivo non disponibile, ordine accettato col controllo locale', ['error' => $e->getMessage()]);
            $unverified = true;
        }

        if ($quote instanceof SupplierQuote) {
            $result = QuoteReconciler::reconcile($view['lines'], $quote, $this->settings->bool('catalog.sell_extended'));
            if ($result['reduce'] !== []) {
                $reduce = $result['reduce'];
                $notices = $this->reduce($cart, $view, static fn (array $product, array $unit): ?int => $reduce[$unit['key']] ?? null);

                return ['status' => 'changed', 'notices' => $notices];
            }
            $confirmed = $this->carts->build($cart->items(), (string) $customer['country_code'], $destination, $result['split']);
            if ($confirmed['tax']->totalCents > $view['tax']->totalCents) {
                // Meno paia in pronta consegna del previsto: prezzi ricalcolati sulla ripartizione del fornitore.
                $session->set(self::SPLIT, ['hash' => $cart->hash(), 'split' => $result['split']]);

                return ['status' => 'changed', 'notices' => [['type' => 'prices_updated', 'from' => $view['tax']->totalCents, 'to' => $confirmed['tax']->totalCents]]];
            }
            $view = $confirmed;
            $costs = $result['costs'];
        }

        // 3. Salvataggio, email, carrello svuotato.
        $orderId = $this->orders->create($customer, $data, $view, $quote, $costs, $unverified, $token, $ip, $userAgent, $this->supplier->mode(), $locale);
        $this->limiter->hit('checkout_user', (string) $userId);
        $this->limiter->hit('checkout_ip', $ip);
        $done = is_array($done) ? $done : [];
        $done[$token] = $orderId;
        $session->set(self::DONE, array_slice($done, -10, null, true));
        $session->remove(self::TOKEN);
        $session->remove(self::SPLIT);
        $cart->clear();
        $this->orders->notifyCreated($orderId);
        $this->logger->info('Richiesta d\'ordine salvata', ['order' => $orderId, 'unverified' => $unverified]);

        return ['status' => 'created', 'order_id' => $orderId];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $customer
     * @return array{data: array<string, string>, errors: array<string, string>}
     */
    public function validate(array $input, array $customer): array
    {
        $get = static fn (string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $errors = [];
        $data = [
            'contact_name' => $get('contact_name'),
            'email' => strtolower($get('email')),
            'phone' => $get('phone'),
            'ship_to_mode' => $get('ship_to_mode') === 'other' ? 'other' : 'billing',
            'notes' => mb_substr($get('notes'), 0, 1000),
        ];
        if ($data['contact_name'] === '' || mb_strlen($data['contact_name']) > 150) {
            $errors['contact_name'] = 'validation.required';
        }
        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'validation.email';
        }
        if (preg_match('/^\+?[0-9 ()\/.\-]{6,25}$/', $data['phone']) !== 1) {
            $errors['phone'] = 'validation.phone';
        }
        foreach (['ship_name', 'ship_company', 'ship_street', 'ship_city', 'ship_zip', 'ship_province', 'ship_phone'] as $field) {
            $data[$field] = mb_substr($get($field), 0, 150);
        }
        $data['ship_country'] = strtoupper($get('ship_country'));
        if ($data['ship_to_mode'] === 'other') {
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
            $data['ship_province'] = strtoupper($data['ship_province']);
            if ($data['ship_country'] === 'IT' && preg_match('/^[A-Z]{2}$/', $data['ship_province']) !== 1) {
                $errors['ship_province'] = 'validation.province';
            }
            if ($data['ship_phone'] !== '' && preg_match('/^\+?[0-9 ()\/.\-]{6,25}$/', $data['ship_phone']) !== 1) {
                $errors['ship_phone'] = 'validation.phone';
            }
        }
        if (!$this->countries->isServed((string) $customer['country_code'])) {
            $errors['billing'] = 'validation.country';
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * Riduce le quantità del carrello secondo il fornitore e restituisce cosa è cambiato.
     *
     * @param array<string, mixed> $view
     * @param callable(array<string, mixed>, array<string, mixed>): ?int $available
     * @return list<array<string, mixed>>
     */
    private function reduce(Cart $cart, array $view, callable $available): array
    {
        $notices = [];
        foreach ($view['lines'] as $line) {
            $product = $line['product'];
            $unit = $line['unit'];
            $max = $available($product, $unit);
            if ($max !== null && $max < (int) $unit['quantity']) {
                $cart->set((string) $unit['key'], $max);
                $notices[] = [
                    'type' => $max === 0 ? 'removed' : 'reduced', 'sku' => $product['sku'], 'name' => $product['name'],
                    'size' => $unit['size_eu'], 'regime' => $unit['regime'], 'from' => (int) $unit['quantity'], 'to' => $max,
                ];
            }
        }
        if ($notices === []) {
            $notices[] = ['type' => 'unavailable'];
        }

        return $notices;
    }

    /** Ordini dell'utente nell'ultima ora (per il riepilogo dei limiti). */
    public function recentOrders(int $userId): int
    {
        return $this->db->int('SELECT COUNT(*) FROM orders WHERE user_id = :u AND created_at > :t', ['u' => $userId, 't' => $this->clock->isoAgo(3600)]);
    }
}
