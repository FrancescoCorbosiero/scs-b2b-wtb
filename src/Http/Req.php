<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Session;
use Psr\Http\Message\ServerRequestInterface;

/** Accesso tipizzato agli attributi che i middleware mettono sulla richiesta. */
final class Req
{
    public static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute('session');
        if (!$session instanceof Session) {
            throw new \LogicException('Sessione non disponibile su questa rotta.');
        }

        return $session;
    }

    /** @return array<string, mixed>|null */
    public static function customer(ServerRequestInterface $request): ?array
    {
        $user = $request->getAttribute('customer');

        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed> */
    public static function requireCustomer(ServerRequestInterface $request): array
    {
        return self::customer($request) ?? throw new \LogicException('Cliente non collegato.');
    }

    public static function ip(ServerRequestInterface $request): string
    {
        return (string) ($request->getAttribute('client_ip') ?? '0.0.0.0');
    }

    public static function userAgent(ServerRequestInterface $request): string
    {
        return mb_substr($request->getHeaderLine('User-Agent'), 0, 255);
    }

    public static function adminName(ServerRequestInterface $request): string
    {
        return (string) ($request->getAttribute('admin_name') ?? 'admin');
    }

    /** @return array<string, mixed> */
    public static function input(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    public static function str(ServerRequestInterface $request, string $key, string $default = ''): string
    {
        $value = self::input($request)[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function query(ServerRequestInterface $request, string $key, string $default = ''): string
    {
        $value = $request->getQueryParams()[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json')
            || str_contains($request->getHeaderLine('Content-Type'), 'application/json');
    }

    /** Percorso interno sicuro per i redirect "next" (mai verso altri siti). */
    public static function safeNext(?string $next, string $fallback): string
    {
        if ($next === null || $next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return $fallback;
        }

        return $next;
    }
}
