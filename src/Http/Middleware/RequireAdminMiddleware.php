<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ViewContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Area admin: solo con la sessione admin. */
final class RequireAdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly ViewContext $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->context->isAdmin) {
            return $handler->handle($request);
        }
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $response = $this->responses->createResponse(401);
            $response->getBody()->write('{"error":"admin_login_required"}');

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $this->responses->createResponse(303)->withHeader('Location', '/admin/login');
    }
}
