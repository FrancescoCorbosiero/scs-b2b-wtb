<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Http\Req;
use App\Http\Responder;
use App\Orders\Cart;
use App\Orders\CartService;
use App\Shipping\CountryRepository;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /carrello: per ogni prodotto la tabella taglie × regimi con fasce, prezzi e quantità; "Prendi tutto" e
 * "Svuota" per prodotto; riepilogo con spedizione, IVA stimata, consegna stimata e ordine minimo.
 */
final class CartController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CartService $carts,
        private readonly CountryRepository $countries,
        private readonly Translator $t,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $customer = Req::requireCustomer($request);
        $cart = new Cart(Req::session($request));
        $view = $this->carts->build($cart->items(), (string) $customer['country_code'], $cart->country() ?? (string) $customer['country_code']);
        if ($view['notices'] !== []) {
            // Lo stock si ricontrolla a ogni apertura: il carrello si adegua e il cliente vede cosa è cambiato.
            $cart->replace($view['items']);
        }

        return $this->responder->render($response, 'cart/index.twig', ['cart' => $view, 'customer' => $customer]);
    }

    /** Dalla scheda rapida: imposta le quantità delle taglie del prodotto (qty[chiave] = paia). */
    public function add(Request $request, Response $response): Response
    {
        $cart = new Cart(Req::session($request));
        foreach ($this->quantities($request) as $key => $qty) {
            $cart->set($key, $qty);
        }
        $customer = Req::requireCustomer($request);
        if (Req::wantsJson($request)) {
            $view = $this->carts->build($cart->items(), (string) $customer['country_code'], $cart->country() ?? (string) $customer['country_code']);

            return $this->responder->json($response, ['ok' => true, 'cart' => CartService::summary($view), 'message' => $this->t->get('cart.updated')]);
        }
        Req::session($request)->flash('success', $this->t->get('cart.updated'));

        return $this->responder->redirect($response, '/carrello');
    }

    /** Aggiorna tutte le quantità; "Prendi tutto" e "Svuota" per prodotto. */
    public function update(Request $request, Response $response): Response
    {
        $customer = Req::requireCustomer($request);
        $cart = new Cart(Req::session($request));
        $quantities = $this->quantities($request);
        $view = $this->carts->build($cart->items() + array_fill_keys(array_keys($quantities), 0), (string) $customer['country_code'], $cart->country());
        $takeAll = (int) Req::str($request, 'take_all');
        $clearProduct = (int) Req::str($request, 'clear_product');
        foreach ($view['products'] as $product) {
            foreach ($product['units'] as $unit) {
                $key = (string) $unit['key'];
                if ($product['id'] === $clearProduct) {
                    $cart->set($key, 0);
                } elseif ($product['id'] === $takeAll) {
                    $cart->set($key, (int) $unit['max']);
                } elseif (array_key_exists($key, $quantities)) {
                    $cart->set($key, $quantities[$key]);
                }
            }
        }
        $country = strtoupper(Req::str($request, 'destination'));
        if ($country !== '' && $this->countries->isServed($country)) {
            $cart->setCountry($country);
        }
        Req::session($request)->flash('success', $this->t->get('cart.updated'));

        return $this->responder->redirect($response, '/carrello');
    }

    public function clear(Request $request, Response $response): Response
    {
        (new Cart(Req::session($request)))->clear();
        Req::session($request)->flash('success', $this->t->get('cart.cleared'));

        return $this->responder->redirect($response, '/carrello');
    }

    /** @return array<string, int> */
    private function quantities(Request $request): array
    {
        $raw = Req::input($request)['qty'] ?? [];
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (is_string($key) && Cart::parseKey($key) !== null && is_scalar($value) && preg_match('/^\d{1,4}$/', trim((string) $value)) === 1) {
                $out[$key] = (int) $value;
            }
        }

        return $out;
    }
}
