<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Req;
use App\Http\Responder;
use App\Settings\SettingsService;
use App\Shipping\CountryRepository;
use App\Shipping\ZoneRepository;
use App\Supplier\ShippingSnapshot;
use App\Support\Money;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * /admin/spedizione (docs/05): zone, tariffe per ordine, soglie di gratuità, tempi di transito e paesi, con
 * accanto quanto costerebbe al fornitore un ordine minimo verso la zona (dall'ultima fotografia del sync).
 */
final class ShippingController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ZoneRepository $zones,
        private readonly CountryRepository $countries,
        private readonly ShippingSnapshot $snapshot,
        private readonly SettingsService $settings,
        private readonly Translator $t,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $snapshot = $this->snapshot->latest();
        $profile = $snapshot['profile'] ?? null;
        $pairs = max(1, $this->settings->int('orders.min_items'));
        $countries = $this->countries->all();
        $zones = [];
        foreach ($this->zones->all() as $zone) {
            $codes = [];
            foreach ($countries as $code => $country) {
                if ($country['zone'] === $zone['zone']) {
                    $codes[] = (string) $code;
                }
            }
            $served = array_values(array_filter($codes, static fn (string $c): bool => (int) $countries[$c]['served'] === 1));
            // Costo del fornitore per un ordine minimo tutto in pronta consegna, sui paesi serviti della zona.
            $costs = [];
            foreach ($profile === null ? [] : $served as $code) {
                $supplierZone = $profile->zoneFor($code);
                if ($supplierZone !== null) {
                    $costs[$code] = $supplierZone->costFor($pairs);
                }
            }
            $zones[] = $zone + [
                'countries' => $codes,
                'served' => $served,
                'cost_min' => $costs === [] ? null : min($costs),
                'cost_max' => $costs === [] ? null : max($costs),
                'missing' => $profile === null ? [] : array_values(array_diff($served, array_keys($costs))),
            ];
        }

        return $this->responder->render($response, 'admin/shipping/index.twig', [
            'zones' => $zones,
            'countries' => $countries,
            'zone_codes' => array_column($zones, 'zone'),
            'profile' => $profile,
            'snapshot_at' => $snapshot['fetched_at'] ?? null,
            'pairs' => $pairs,
        ]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $session = Req::session($request);
        $zone = $this->zones->find($args['zone']);
        if ($zone === null) {
            $session->flash('error', $this->t->get('admin.shipping.unknown_zone'));

            return $this->responder->redirect($response, '/admin/spedizione');
        }
        $data = $this->validate($request);
        if ($data === null) {
            $session->flash('error', $this->t->get('admin.shipping.invalid'));

            return $this->responder->redirect($response, '/admin/spedizione#zona-' . $zone['zone']);
        }
        $this->zones->update((string) $zone['zone'], $data);
        $this->logger->info('Zona di spedizione modificata', ['zone' => $zone['zone'], 'by' => Req::adminName($request), 'before' => array_intersect_key($zone, $data), 'after' => $data]);
        $session->flash('success', $this->t->get('admin.shipping.saved', ['zone' => (string) $data['name_it']]));

        return $this->responder->redirect($response, '/admin/spedizione#zona-' . $zone['zone']);
    }

    public function create(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $code = strtoupper(Req::str($request, 'zone'));
        $data = $this->validate($request);
        if ($data === null || preg_match('/^[A-Z][A-Z0-9_]{1,11}$/', $code) !== 1 || $this->zones->find($code) !== null) {
            $session->flash('error', $this->t->get('admin.shipping.invalid_new'));

            return $this->responder->redirect($response, '/admin/spedizione#nuova-zona');
        }
        $this->zones->create($code, $data);
        $this->logger->info('Zona di spedizione creata', ['zone' => $code, 'by' => Req::adminName($request), 'data' => $data]);
        $session->flash('success', $this->t->get('admin.shipping.created', ['zone' => $code]));

        return $this->responder->redirect($response, '/admin/spedizione#zona-' . $code);
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $ok = $this->zones->delete($args['zone']);
        if ($ok) {
            $this->logger->info('Zona di spedizione eliminata', ['zone' => $args['zone'], 'by' => Req::adminName($request)]);
        }
        Req::session($request)->flash($ok ? 'success' : 'error', $this->t->get($ok ? 'admin.shipping.deleted' : 'admin.shipping.not_deletable'));

        return $this->responder->redirect($response, '/admin/spedizione');
    }

    /** Zona e "servito" di ogni paese in un solo invio. */
    public function countries(Request $request, Response $response): Response
    {
        $input = Req::input($request);
        $zonesIn = is_array($input['zone'] ?? null) ? $input['zone'] : [];
        $servedIn = is_array($input['served'] ?? null) ? $input['served'] : [];
        $valid = array_column($this->zones->all(), 'zone');
        $changed = 0;
        foreach ($this->countries->all() as $code => $country) {
            $zone = is_string($zonesIn[$code] ?? null) ? $zonesIn[$code] : (string) $country['zone'];
            if (!in_array($zone, $valid, true)) {
                continue;
            }
            $served = ($servedIn[$code] ?? '') === '1';
            if ($zone !== $country['zone'] || $served !== ((int) $country['served'] === 1)) {
                $this->countries->setZone((string) $code, $zone, $served);
                $changed++;
            }
        }
        $served = array_filter($this->countries->all(), static fn (array $c): bool => (int) $c['served'] === 1);
        if ($served === []) {
            // Almeno un paese servito: senza, nessuno potrebbe ordinare.
            $this->countries->setZone('IT', (string) ($this->countries->find('IT')['zone'] ?? 'IT'), true);
        }
        if ($changed > 0) {
            $this->logger->info('Paesi serviti modificati', ['changed' => $changed, 'by' => Req::adminName($request)]);
        }
        Req::session($request)->flash('success', $this->t->get('admin.shipping.countries_saved', ['n' => $changed]));

        return $this->responder->redirect($response, '/admin/spedizione#paesi');
    }

    /** @return array{name_it: string, name_en: string, fee_cents: int, free_from_items: ?int, transit_days_min: int, transit_days_max: int, sort_order: int}|null */
    private function validate(Request $request): ?array
    {
        $nameIt = trim(Req::str($request, 'name_it'));
        $nameEn = trim(Req::str($request, 'name_en'));
        $fee = Money::parseInput(Req::str($request, 'fee'));
        $free = trim(Req::str($request, 'free_from_items'));
        $min = Req::str($request, 'transit_days_min');
        $max = Req::str($request, 'transit_days_max');
        $sort = Req::str($request, 'sort_order', '100');
        if ($nameIt === '' || $nameEn === '' || mb_strlen($nameIt) > 60 || mb_strlen($nameEn) > 60
            || $fee === null || $fee < 0 || $fee > 100000
            || ($free !== '' && preg_match('/^[1-9]\d{0,2}$/', $free) !== 1)
            || preg_match('/^\d{1,2}$/', $min) !== 1 || preg_match('/^\d{1,2}$/', $max) !== 1 || (int) $min > (int) $max
            || preg_match('/^\d{1,3}$/', $sort) !== 1) {
            return null;
        }

        return [
            'name_it' => $nameIt,
            'name_en' => $nameEn,
            'fee_cents' => $fee,
            'free_from_items' => $free === '' ? null : (int) $free,
            'transit_days_min' => (int) $min,
            'transit_days_max' => (int) $max,
            'sort_order' => (int) $sort,
        ];
    }
}
