<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

use App\Support\Clock;

/**
 * Firma dei webhook (docs/06): X-WTB-Signature: t=<unix>,v1=<hex>, HMAC-SHA256 di "<t>.<corpo grezzo>"
 * con WTB_WEBHOOK_SECRET, confronto a tempo costante, finestra di 5 minuti.
 */
final class WebhookVerifier
{
    public const TOLERANCE = 300;

    public function __construct(
        private readonly string $secret,
        private readonly Clock $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }

    public function verify(string $header, string $rawBody): bool
    {
        if ($this->secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $kv = explode('=', trim($piece), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]][] = $kv[1];
            }
        }
        $timestamp = $parts['t'][0] ?? '';
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs($this->clock->timestamp() - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, strtolower($candidate))) {
                return true;
            }
        }

        return false;
    }

    /** Firma come la produce il fornitore (per "Simula evento" e per i test). */
    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }
}
