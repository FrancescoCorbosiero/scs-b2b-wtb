<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Accounts\AccountRequestService;
use App\Http\Req;
use App\Http\Responder;
use App\Settings\SettingsService;
use App\Shipping\DeliveryEstimate;
use App\Shipping\ShippingInfo;
use App\Support\Config;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pagine pubbliche di presentazione (indicizzabili): mai prodotti, foto prodotto o prezzi (docs/07, docs/09).
 * Tariffe, tempi e ordine minimo si leggono dai valori reali: cambiando i dati cambiano anche i testi.
 */
final class PageController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ShippingInfo $shipping,
        private readonly SettingsService $settings,
        private readonly AccountRequestService $requests,
        private readonly Config $config,
        private readonly Translator $t,
    ) {
    }

    public function home(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'site/home.twig', $this->shippingData() + ['indexable' => true]);
    }

    public function how(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'site/how.twig', $this->shippingData() + ['indexable' => true]);
    }

    public function shipping(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'site/shipping.twig', $this->shippingData() + ['indexable' => true]);
    }

    public function contacts(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'site/contacts.twig', [
            'indexable' => true,
            'bank' => $this->bank(),
        ]);
    }

    public function requestForm(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'site/request.twig', ['indexable' => true]);
    }

    public function requestSubmit(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $input = Req::input($request);
        $result = $this->requests->submit($input, Req::ip($request), Req::userAgent($request));
        switch ($result['status']) {
            case 'ok':
                $session->flash('success', $this->t->get('request.sent'));

                return $this->responder->redirect($response, '/richiedi-accesso/inviata');
            case 'limited':
                $session->flash('error', $this->t->get('request.limited'));
                break;
            case 'duplicate':
                $session->flash('error', $this->t->get('request.duplicate'));
                break;
            default:
                $session->flash('error', $this->t->get('validation.fix_errors'));
                $session->flashErrors($result['errors'] ?? []);
        }
        $session->flashInput($input);

        return $this->responder->redirect($response, '/richiedi-accesso');
    }

    public function requestSent(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'site/request_sent.twig');
    }

    public function health(Request $request, Response $response): Response
    {
        $response->getBody()->write('ok');

        return $response->withHeader('Content-Type', 'text/plain');
    }

    /** @return array<string, mixed> */
    private function shippingData(): array
    {
        $departure = $this->shipping->departureDays();
        $zones = [];
        foreach ($this->shipping->zonesWithCountries() as $item) {
            $zone = $item['zone'];
            $dep = [
                'immediate' => DeliveryEstimate::parseRange($departure['immediate']),
                'extended' => DeliveryEstimate::parseRange($departure['extended']),
            ];
            $zones[] = [
                'zone' => $zone,
                'countries' => $item['countries'],
                'delivery_immediate' => $dep['immediate'] === null ? null : DeliveryEstimate::delivery($dep['immediate'], $zone->transitMin, $zone->transitMax),
                'delivery_extended' => $dep['extended'] === null ? null : DeliveryEstimate::delivery($dep['extended'], $zone->transitMin, $zone->transitMax),
            ];
        }

        return [
            'zones' => $zones,
            'departure' => $departure,
            'duties_countries' => $this->shipping->dutiesCountries(),
            'min_items' => $this->settings->int('orders.min_items'),
            'sell_extended' => $this->settings->bool('catalog.sell_extended'),
        ];
    }

    /** @return array{holder: string, bank: string, iban: string, bic: string} */
    private function bank(): array
    {
        return [
            'holder' => $this->config->string('BANK_HOLDER'),
            'bank' => $this->config->string('BANK_NAME'),
            'iban' => $this->config->string('BANK_IBAN'),
            'bic' => $this->config->string('BANK_BIC'),
        ];
    }
}
