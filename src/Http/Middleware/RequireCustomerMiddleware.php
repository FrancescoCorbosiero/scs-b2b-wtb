<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Req;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Catalogo, carrello e account: solo con un cliente collegato (regola d'oro 5). */
final class RequireCustomerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (Req::customer($request) !== null) {
            return $handler->handle($request);
        }
        if (Req::wantsJson($request)) {
            $response = $this->responses->createResponse(401);
            $response->getBody()->write('{"error":"login_required"}');

            return $response->withHeader('Content-Type', 'application/json');
        }
        $uri = $request->getUri();
        $next = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
        if ($request->getMethod() !== 'GET') {
            $next = '/catalogo';
        }

        return $this->responses->createResponse(303)->withHeader('Location', '/login?next=' . rawurlencode($next));
    }
}
