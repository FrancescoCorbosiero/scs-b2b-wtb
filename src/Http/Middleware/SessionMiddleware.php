<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ViewContext;
use App\Support\Config;
use App\Support\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sessione server-side con cookie HttpOnly, SameSite=Lax, Secure (docs/09).
 * Area admin con sessione distinta (cookie con Path=/admin). Niente sessione su webhook e immagini.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public const SITE_COOKIE = 'scs_session';
    public const ADMIN_COOKIE = 'scs_admin';

    public function __construct(
        private readonly SessionStore $store,
        private readonly ViewContext $context,
        private readonly Config $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (self::isStateless($path)) {
            return $handler->handle($request);
        }
        $isAdmin = $path === '/admin' || str_starts_with($path, '/admin/');
        $scope = $isAdmin ? 'admin' : 'site';
        $cookieName = $isAdmin ? self::ADMIN_COOKIE : self::SITE_COOKIE;
        $cookies = $request->getCookieParams();
        $incoming = isset($cookies[$cookieName]) && is_string($cookies[$cookieName]) ? $cookies[$cookieName] : null;

        $session = $this->store->load($scope, $incoming);
        $originalId = $session->id();
        $this->context->session = $session;
        $this->context->area = $scope;

        $response = $handler->handle($request->withAttribute('session', $session)->withAttribute('session_scope', $scope));

        if ($session->isDirty()) {
            $this->store->save($scope, $session, $session->isNew() ? null : ($incoming ?? $originalId));
        } elseif (!$session->isNew()) {
            $this->store->touch($scope, $session);
        }
        // Cookie rinnovato a ogni risposta finché la sessione ha dati (scadenza scorrevole, come lato server);
        // cancellato se la sessione è stata svuotata (logout).
        if ($session->all() !== []) {
            $response = $response->withAddedHeader('Set-Cookie', $this->cookie($cookieName, $session->id(), $isAdmin ? '/admin' : '/', $request));
        } elseif ($incoming !== null) {
            $response = $response->withAddedHeader('Set-Cookie', $this->cookie($cookieName, '', $isAdmin ? '/admin' : '/', $request, true));
        }

        return $response;
    }

    public static function isStateless(string $path): bool
    {
        return str_starts_with($path, '/webhooks/') || str_starts_with($path, '/img/') || str_starts_with($path, '/assets/')
            || $path === '/robots.txt' || $path === '/healthz' || $path === '/favicon.ico';
    }

    private function cookie(string $name, string $value, string $path, ServerRequestInterface $request, bool $expire = false): string
    {
        $secureSetting = $this->config->string('SESSION_SECURE', 'auto');
        $secure = $this->config->isProduction() || $secureSetting === '1' || ($secureSetting === 'auto' && $request->getAttribute('is_https') === true);
        $parts = [
            $name . '=' . $value,
            'Path=' . $path,
            'HttpOnly',
            'SameSite=Lax',
        ];
        $parts[] = $expire ? 'Max-Age=0' : 'Max-Age=' . $this->store->lifetime();
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
