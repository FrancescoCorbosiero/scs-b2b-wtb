<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Req;
use App\Http\Responder;
use App\Settings\InvalidSetting;
use App\Settings\Registry;
use App\Settings\SettingsService;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /admin/impostazioni: pagina generata dal registro, con storico e "Ripristina default". */
final class SettingsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly SettingsService $settings,
        private readonly Translator $t,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $groups = [];
        foreach ($this->settings->registry()->grouped() as $group => $definitions) {
            $items = [];
            foreach ($definitions as $definition) {
                $value = $this->settings->get($definition->key);
                $items[] = [
                    'def' => $definition,
                    'value' => $value,
                    'input' => $definition->formatInput($value),
                    'customized' => $this->settings->isCustomized($definition->key),
                    'default_text' => $this->settings->describeStored($definition->key, null),
                ];
            }
            $groups[] = ['key' => $group, 'label' => Registry::GROUPS[$group] ?? $group, 'items' => $items];
        }
        $audit = array_map(fn (array $row): array => $row + [
            'old_text' => $this->settings->describeStored((string) $row['key'], $row['old_value'] === null ? null : (string) $row['old_value']),
            'new_text' => $this->settings->describeStored((string) $row['key'], $row['new_value'] === null ? null : (string) $row['new_value']),
            'label' => $this->settings->registry()->has((string) $row['key']) ? $this->settings->registry()->get((string) $row['key'])->label : (string) $row['key'],
        ], $this->settings->audit(null, 50));

        return $this->responder->render($response, 'admin/settings.twig', ['groups' => $groups, 'audit' => $audit]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $key = $args['key'];
        $session = Req::session($request);
        if (!$this->settings->registry()->has($key)) {
            throw new HttpNotFoundException($request);
        }
        $definition = $this->settings->registry()->get($key);
        $input = $definition->type === 'bool' ? (Req::str($request, 'value') === '1' ? '1' : '0') : Req::str($request, 'value');
        try {
            $this->settings->setFromInput($key, $input, Req::adminName($request));
            $session->flash('success', $this->t->get('admin.settings.saved', ['label' => $definition->label]));
        } catch (InvalidSetting $e) {
            $session->flash('error', $definition->label . ': ' . $e->getMessage());
        }

        return $this->responder->redirect($response, '/admin/impostazioni#' . rawurlencode($key));
    }

    /** @param array<string, string> $args */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $key = $args['key'];
        if (!$this->settings->registry()->has($key)) {
            throw new HttpNotFoundException($request);
        }
        $this->settings->reset($key, Req::adminName($request));
        Req::session($request)->flash('success', $this->t->get('admin.settings.reset_done', ['label' => $this->settings->registry()->get($key)->label]));

        return $this->responder->redirect($response, '/admin/impostazioni#' . rawurlencode($key));
    }
}
