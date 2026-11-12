<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IP reale del cliente dietro Caddy/nginx (docs/09): X-Forwarded-For e X-Forwarded-Proto
 * contano solo se la connessione arriva da un proxy fidato (TRUSTED_PROXIES, CIDR).
 */
final class ClientIp
{
    /** @param list<string> $trustedProxies */
    public function __construct(private readonly array $trustedProxies)
    {
    }

    /** @param array<string, mixed> $server */
    public function resolve(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->isTrusted($remote)) {
            return $remote;
        }
        $forwarded = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') {
            return $remote;
        }
        $chain = array_reverse(array_map('trim', explode(',', $forwarded)));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!$this->isTrusted($ip)) {
                return $ip;
            }
        }

        return $remote;
    }

    /** @param array<string, mixed> $server */
    public function isHttps(array $server): bool
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($this->isTrusted($remote) && isset($server['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower(trim(explode(',', (string) $server['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
        }
        $https = strtolower((string) ($server['HTTPS'] ?? ''));

        return $https !== '' && $https !== 'off';
    }

    public function isTrusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        $bits = $bits === null ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
