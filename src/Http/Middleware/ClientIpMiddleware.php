<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ViewContext;
use App\Support\ClientIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ClientIpMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ClientIp $clientIp,
        private readonly ViewContext $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // REMOTE_ADDR dal server, X-Forwarded-* dalle intestazioni (valgono solo da un proxy fidato).
        $server = [
            'REMOTE_ADDR' => (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0'),
            'HTTPS' => (string) ($request->getServerParams()['HTTPS'] ?? ($request->getUri()->getScheme() === 'https' ? 'on' : '')),
            'HTTP_X_FORWARDED_FOR' => $request->getHeaderLine('X-Forwarded-For'),
        ];
        if ($request->hasHeader('X-Forwarded-Proto')) {
            $server['HTTP_X_FORWARDED_PROTO'] = $request->getHeaderLine('X-Forwarded-Proto');
        }
        $this->context->reset();
        $this->context->path = $request->getUri()->getPath();
        $this->context->query = $request->getQueryParams();
        $this->context->isHttps = $this->clientIp->isHttps($server);

        return $handler->handle(
            $request
                ->withAttribute('client_ip', $this->clientIp->resolve($server))
                ->withAttribute('is_https', $this->context->isHttps),
        );
    }
}
