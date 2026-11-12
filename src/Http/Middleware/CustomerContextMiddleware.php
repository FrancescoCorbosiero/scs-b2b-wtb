<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Accounts\CustomerAuth;
use App\Http\ViewContext;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Carica il cliente collegato (se c'è) su tutte le pagine del sito; un account disattivato
 * chiude la sessione alla richiesta successiva (docs/09). Nell'area admin carica lo stato admin.
 */
final class CustomerContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CustomerAuth $auth,
        private readonly ViewContext $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute('session');
        if ($session instanceof Session) {
            if ($request->getAttribute('session_scope') === 'admin') {
                $this->context->isAdmin = $session->get('admin') === true;
                $this->context->adminName = (string) $session->get('admin_name', '');
                $request = $request->withAttribute('admin_name', $this->context->adminName);
            } else {
                $customer = $this->auth->current($session);
                $this->context->customer = $customer;
                $this->context->cartCount = $customer === null ? 0 : (new \App\Orders\Cart($session))->pairs();
                $request = $request->withAttribute('customer', $customer);
            }
        }

        return $handler->handle($request);
    }
}
