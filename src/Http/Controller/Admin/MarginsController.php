<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Req;
use App\Http\Responder;
use App\Pricing\MarginResolver;
use App\Pricing\MarginRuleRepository;
use App\Pricing\MarginRuleService;
use App\Settings\SettingsService;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /admin/margini: regole con priorità, attiva/disattiva, prodotti toccati, esempio di prezzo; ogni modifica fa il reprice. */
final class MarginsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly MarginRuleRepository $rules,
        private readonly MarginRuleService $service,
        private readonly MarginResolver $resolver,
        private readonly SettingsService $settings,
        private readonly Translator $t,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $vat = $this->settings->int('tax.vat_rate_it');
        $rows = [];
        foreach ($this->rules->all() as $rule) {
            $impact = $this->service->impact($rule);
            if ($impact['example'] !== null) {
                $e = $impact['example'];
                $impact['example']['net'] = MarginResolver::netMarginEstimate((int) $e['sale_price_cents'], (int) $e['cost_cents'], (string) $e['vat_regime'], $vat);
            }
            $rows[] = ['rule' => $rule, 'impact' => $impact, 'value_input' => MarginRuleService::formatValue($rule->marginType, $rule->marginValue)];
        }
        $default = $this->resolver->defaultSpec();

        return $this->responder->render($response, 'admin/margins/index.twig', [
            'rules' => $rows,
            'default' => $default,
            'default_input' => MarginRuleService::formatValue($default->type, $default->value),
            'rounding' => $this->settings->string('prices.rounding'),
            'edit' => null,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $result = $this->service->validate(Req::input($request));
        if ($result['errors'] !== []) {
            $session->flash('error', implode(' ', $result['errors']));
            $session->flashInput(Req::input($request));

            return $this->responder->redirect($response, '/admin/margini#nuova');
        }
        $this->service->create($result['data'], Req::adminName($request));
        $session->flash('success', $this->t->get('admin.margins.created'));

        return $this->responder->redirect($response, '/admin/margini');
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $this->rules->find($id) ?? throw new HttpNotFoundException($request);
        $session = Req::session($request);
        $result = $this->service->validate(Req::input($request));
        if ($result['errors'] !== []) {
            $session->flash('error', implode(' ', $result['errors']));

            return $this->responder->redirect($response, '/admin/margini#regola-' . $id);
        }
        $this->service->update($id, $result['data'], Req::adminName($request));
        $session->flash('success', $this->t->get('admin.margins.updated'));

        return $this->responder->redirect($response, '/admin/margini#regola-' . $id);
    }

    /** @param array<string, string> $args */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $this->service->toggle((int) $args['id'], Req::adminName($request));
        Req::session($request)->flash('success', $this->t->get('admin.margins.updated'));

        return $this->responder->redirect($response, '/admin/margini#regola-' . (int) $args['id']);
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->service->delete((int) $args['id']);
        Req::session($request)->flash('success', $this->t->get('admin.margins.deleted'));

        return $this->responder->redirect($response, '/admin/margini');
    }
}
