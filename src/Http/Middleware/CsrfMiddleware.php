<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Req;
use App\Support\Csrf;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;

/** CSRF su ogni POST (docs/09), tranne il webhook (che è firmato). */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || str_starts_with($request->getUri()->getPath(), '/webhooks/')) {
            return $handler->handle($request);
        }
        $session = $request->getAttribute('session');
        $submitted = Req::input($request)[Csrf::FIELD] ?? $request->getHeaderLine(Csrf::HEADER);
        if (!$session instanceof Session || !Csrf::isValid($session, is_string($submitted) ? $submitted : null)) {
            throw new HttpForbiddenException($request, 'csrf');
        }

        return $handler->handle($request);
    }
}
