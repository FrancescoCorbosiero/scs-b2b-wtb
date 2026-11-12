<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Http\Req;
use App\Http\Responder;
use App\Orders\Cart;
use App\Orders\CartService;
use App\Orders\CheckoutService;
use App\Orders\OrderMailer;
use App\Orders\OrderPresenter;
use App\Orders\OrderRepository;
use App\Settings\SettingsService;
use App\Support\Config;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** Richiesta d'ordine: modulo, ripensamento (JS), preventivo, conferma con istruzioni di bonifico (docs/06). */
final class CheckoutController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
        private readonly OrderRepository $orders,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly Translator $t,
        private readonly \App\Shipping\ShippingInfo $shippingInfo,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        $customer = Req::requireCustomer($request);
        $session = Req::session($request);
        $cart = new Cart($session);
        $destination = $this->destination($session, $customer);
        $view = $this->carts->build($cart->items(), (string) $customer['country_code'], $destination, $this->checkout->splitOverride($session, $cart));
        if ($view['empty']) {
            return $this->responder->redirect($response, '/carrello');
        }
        if ($view['notices'] !== []) {
            $cart->replace($view['items']);
        }

        $notices = $session->get('checkout_notices', []);
        $session->remove('checkout_notices');

        return $this->responder->render($response, 'checkout/index.twig', [
            'cart' => $view,
            'customer' => $customer,
            'token' => $this->checkout->token($session),
            'rethink_seconds' => $this->settings->int('orders.rethink_seconds'),
            'notices' => is_array($notices) ? $notices : [],
            'duties' => $this->shippingInfo->hasDuties((string) $view['destination']),
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        $customer = Req::requireCustomer($request);
        $session = Req::session($request);
        $input = Req::input($request);
        $result = $this->checkout->submit($session, new Cart($session), $customer, $input, Req::ip($request), Req::userAgent($request), (string) $request->getAttribute('locale', 'it'));
        if (in_array($result['status'], ['created', 'already'], true)) {
            return $this->responder->redirect($response, '/checkout/completato/' . ($result['order_id'] ?? 0));
        }
        $session->flashInput($input);
        $session->set('checkout_destination', ($input['ship_to_mode'] ?? '') === 'other'
            ? strtoupper(is_string($input['ship_country'] ?? null) ? $input['ship_country'] : '')
            : (string) $customer['country_code']);
        match ($result['status']) {
            'invalid' => $this->invalid($session, $result['errors'] ?? []),
            'changed' => $session->set('checkout_notices', $result['notices'] ?? []),
            default => $session->flash('error', $this->t->get('checkout.errors.' . $result['status'])),
        };
        if ($result['status'] === 'changed') {
            $session->flash('warning', $this->t->get('checkout.changed'));
        }
        if ($result['status'] === 'empty') {
            return $this->responder->redirect($response, '/carrello');
        }

        return $this->responder->redirect($response, '/checkout');
    }

    /** @param array<string, string> $args */
    public function done(Request $request, Response $response, array $args): Response
    {
        $customer = Req::requireCustomer($request);
        $order = $this->orders->find((int) $args['id']);
        if ($order === null || (int) $order['user_id'] !== (int) $customer['id']) {
            throw new HttpNotFoundException($request);
        }

        return $this->responder->render($response, 'checkout/done.twig', [
            'order' => OrderPresenter::forCustomer($order, $this->orders->lines((int) $order['id'])),
            'bank' => OrderMailer::bank($this->config),
        ]);
    }

    /** @param array<string, string> $errors */
    private function invalid(\App\Support\Session $session, array $errors): void
    {
        $session->flash('error', $this->t->get('validation.fix_errors'));
        $session->flashErrors($errors);
    }

    /** @param array<string, mixed> $customer */
    private function destination(\App\Support\Session $session, array $customer): string
    {
        $old = $session->get('checkout_destination');

        return is_string($old) && $old !== '' ? $old : (string) $customer['country_code'];
    }
}
