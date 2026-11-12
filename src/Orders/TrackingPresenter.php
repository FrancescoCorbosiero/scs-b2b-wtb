<?php

declare(strict_types=1);

namespace App\Orders;

use App\Settings\SettingsService;
use App\Support\Db;

/**
 * Tracking per il cliente (docs/06 § Tracking): corriere e numero; il link solo se il dominio è tra i corrieri
 * ammessi (shipping.carrier_domains). Mai un URL del fornitore (regola d'oro 2).
 */
final class TrackingPresenter
{
    public function __construct(
        private readonly Db $db,
        private readonly SettingsService $settings,
    ) {
    }

    /** @return list<array{carrier: string, number: string, url: string}> */
    public function forOrder(int $orderId): array
    {
        try {
            $rows = $this->db->column(
                "SELECT tracking FROM supplier_orders WHERE order_id = :o AND tracking IS NOT NULL AND tracking != '[]' ORDER BY id",
                ['o' => $orderId],
            );
        } catch (\PDOException) {
            $rows = [];
        }
        $manual = $this->db->value('SELECT manual_tracking FROM orders WHERE id = :o', ['o' => $orderId]);
        if (is_string($manual) && $manual !== '') {
            $rows[] = $manual;
        }
        $out = [];
        $seen = [];
        foreach ($rows as $json) {
            foreach ((array) json_decode((string) $json, true) as $item) {
                if (!is_array($item) || !is_string($item['number'] ?? null) || isset($seen[$item['number']])) {
                    continue;
                }
                $seen[$item['number']] = true;
                $out[] = $this->safe($item);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $item @return array{carrier: string, number: string, url: string} */
    public function safe(array $item): array
    {
        $url = is_string($item['url'] ?? null) ? $item['url'] : '';
        $carrier = is_string($item['carrier'] ?? null) ? mb_substr($item['carrier'], 0, 60) : '';

        return [
            // Un "corriere" col nome del fornitore non arriva mai al cliente (regola d'oro 2).
            'carrier' => preg_match('/wtb/i', $carrier) === 1 ? '' : $carrier,
            'number' => mb_substr((string) $item['number'], 0, 100),
            'url' => $this->isCarrierUrl($url) ? $url : '',
        ];
    }

    public function isCarrierUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach ($this->settings->list('shipping.carrier_domains') as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
