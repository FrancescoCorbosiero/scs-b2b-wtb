<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Header di sicurezza (docs/09) e perimetro di indicizzazione: solo le pagine di presentazione
 * sono indicizzabili, tutto il resto porta X-Robots-Tag: noindex, nofollow.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** Pagine pubbliche indicizzabili: mai prodotti, foto prodotto o prezzi. */
    public const INDEXABLE = ['/', '/come-ordinare', '/spedizioni', '/contatti', '/richiedi-accesso'];

    public const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; "
        . "connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = $request->getUri()->getPath();

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        if (!$response->hasHeader('Content-Security-Policy')) {
            $response = $response->withHeader('Content-Security-Policy', self::CSP);
        }
        if (!self::isIndexable($path)) {
            $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        if ($request->getAttribute('is_https') === true) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        if (!$response->hasHeader('Cache-Control') && !self::isIndexable($path)) {
            $response = $response->withHeader('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public static function isIndexable(string $path): bool
    {
        return in_array(rtrim($path, '/') === '' ? '/' : rtrim($path, '/'), self::INDEXABLE, true);
    }
}
