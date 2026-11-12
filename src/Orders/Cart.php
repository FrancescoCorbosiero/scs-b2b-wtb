<?php

declare(strict_types=1);

namespace App\Orders;

use App\Support\Session;

/**
 * Carrello nella sessione server-side (docs/04): conserva solo (variante, regime, quantità).
 * Prezzi e fasce si ricalcolano sempre dalle offerte correnti.
 */
final class Cart
{
    public const KEY = 'cart';

    public function __construct(private readonly Session $session)
    {
    }

    /** Chiave di un'unità vendibile: "<id variante>-<regime>". */
    public static function key(int $variantId, string $regime): string
    {
        return $variantId . '-' . $regime;
    }

    /** @return array{0: int, 1: string}|null */
    public static function parseKey(string $key): ?array
    {
        if (preg_match('/^(\d{1,10})-(margin|ordinary)$/', $key, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], $m[2]];
    }

    /** @return array<string, int> chiave => paia */
    public function items(): array
    {
        $data = $this->session->get(self::KEY, []);
        $items = is_array($data) && is_array($data['items'] ?? null) ? $data['items'] : [];
        $out = [];
        foreach ($items as $key => $qty) {
            if (is_string($key) && self::parseKey($key) !== null && is_int($qty) && $qty > 0) {
                $out[$key] = $qty;
            }
        }

        return $out;
    }

    public function quantity(string $key): int
    {
        return $this->items()[$key] ?? 0;
    }

    public function set(string $key, int $quantity): void
    {
        if (self::parseKey($key) === null) {
            return;
        }
        $items = $this->items();
        if ($quantity <= 0) {
            unset($items[$key]);
        } else {
            $items[$key] = min($quantity, 9999);
        }
        $this->save($items);
    }

    /** @param array<string, int> $items */
    public function replace(array $items): void
    {
        $this->save(array_filter($items, static fn (int $q, string $k): bool => $q > 0 && self::parseKey($k) !== null, ARRAY_FILTER_USE_BOTH));
    }

    public function clear(): void
    {
        $data = $this->data();
        $data['items'] = [];
        $this->session->set(self::KEY, $data);
    }

    public function pairs(): int
    {
        return array_sum($this->items());
    }

    public function country(): ?string
    {
        $country = $this->data()['country'] ?? null;

        return is_string($country) && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    public function setCountry(string $country): void
    {
        $data = $this->data();
        $data['country'] = strtoupper($country);
        $this->session->set(self::KEY, $data);
    }

    /** Impronta delle righe (per riconoscere un carrello cambiato tra due passaggi del checkout). */
    public function hash(): string
    {
        $items = $this->items();
        ksort($items);

        return hash('sha256', (string) json_encode($items));
    }

    /** @param array<string, int> $items */
    private function save(array $items): void
    {
        $data = $this->data();
        $data['items'] = $items;
        $this->session->set(self::KEY, $data);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = $this->session->get(self::KEY, []);

        return is_array($data) ? $data : [];
    }
}
