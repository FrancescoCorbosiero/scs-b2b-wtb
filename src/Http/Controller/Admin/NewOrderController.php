<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Req;
use App\Http\Responder;
use App\Orders\AdminOrderService;
use App\Orders\Cart;
use App\Shipping\CountryChoices;
use App\Supplier\Wtb\WtbConfig;
use App\Support\Session;
use App\Support\SystemFlags;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /admin/ordini/nuovo (D-019): l'admin compone un ordine di prova (prodotti, taglie, indirizzo) senza passare dal
 * lato cliente. Salvato l'ordine, quello al fornitore si crea dalla sua pagina come per ogni altro ordine.
 */
final class NewOrderController
{
    private const ADDRESS = 'admin_order_address';

    public function __construct(
        private readonly Responder $responder,
        private readonly AdminOrderService $service,
        private readonly CountryChoices $countries,
        private readonly WtbConfig $wtb,
        private readonly SystemFlags $flags,
        private readonly Translator $t,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $cart = new Cart($session, Cart::ADMIN_KEY);
        $q = Req::query($request, 'q');
        $address = $this->address($session);
        $profile = $this->flags->getJson('supplier.profile');

        return $this->responder->render($response, 'admin/orders/new.twig', [
            'q' => $q,
            'results' => $this->service->search($q),
            'draft' => $this->service->draft($cart->items(), $address['ship_country'] ?: 'IT'),
            'address' => $address,
            'mode' => $this->wtb->mode,
            'supplier_min' => is_int($profile['min_order'] ?? null) ? $profile['min_order'] : null,
        ]);
    }

    /** Aggiunge o aggiorna paia nella bozza (qty[chiave unità] = paia; 0 toglie la riga). */
    public function add(Request $request, Response $response): Response
    {
        $cart = new Cart(Req::session($request), Cart::ADMIN_KEY);
        $raw = Req::input($request)['qty'] ?? [];
        $mode = Req::str($request, 'mode') === 'set' ? 'set' : 'add';
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (!is_string($key) || Cart::parseKey($key) === null || !is_scalar($value) || preg_match('/^\d{1,4}$/', trim((string) $value)) !== 1) {
                continue;
            }
            $qty = (int) $value;
            if ($mode === 'set') {
                $cart->set($key, $qty);
            } elseif ($qty > 0) {
                $cart->set($key, $cart->quantity($key) + $qty);
            }
        }

        return $this->responder->redirect($response, Req::safeNext(Req::str($request, 'next'), '/admin/ordini/nuovo') . '#bozza');
    }

    public function clear(Request $request, Response $response): Response
    {
        (new Cart(Req::session($request), Cart::ADMIN_KEY))->clear();

        return $this->responder->redirect($response, '/admin/ordini/nuovo');
    }

    public function submit(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $input = Req::input($request);
        $session->set(self::ADDRESS, $this->service->validate($input)['data']);
        $result = $this->service->create(new Cart($session, Cart::ADMIN_KEY), $input, Req::adminName($request));
        foreach ($result['messages'] ?? [] as $message) {
            $session->flash($result['status'] === 'created' ? 'warning' : 'error', $message);
        }
        if ($result['status'] === 'created') {
            $session->flash('success', $this->t->get('admin.new_order.created'));

            return $this->responder->redirect($response, '/admin/ordini/' . ($result['order_id'] ?? 0) . '#fornitore');
        }
        if ($result['status'] === 'invalid') {
            $session->flashErrors($result['errors'] ?? []);
            $session->flash('error', $this->t->get('validation.fix_errors'));
        }
        if ($result['status'] === 'empty') {
            $session->flash('error', $this->t->get('admin.new_order.empty'));
        }

        return $this->responder->redirect($response, '/admin/ordini/nuovo#spedizione');
    }

    /** Ultimo indirizzo usato (comodo per ripetere le prove). @return array<string, string> */
    private function address(Session $session): array
    {
        $saved = $session->get(self::ADDRESS, []);
        $defaults = [
            'ship_name' => '', 'ship_company' => '', 'ship_street' => '', 'ship_city' => '', 'ship_zip' => '', 'ship_province' => '',
            'ship_country' => $this->countries->lastUsed(null) ?? 'IT', 'ship_phone' => '', 'email' => $this->service->defaultEmail(), 'admin_notes' => '',
        ];
        if (!is_array($saved)) {
            return $defaults;
        }
        foreach ($defaults as $key => $value) {
            if (is_string($saved[$key] ?? null) && $saved[$key] !== '') {
                $defaults[$key] = $saved[$key];
            }
        }

        return $defaults;
    }
}
