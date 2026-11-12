<?php

declare(strict_types=1);

namespace App\Http;

use App\Settings\SettingsService;
use App\Shipping\CountryRepository;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Money;
use App\Support\Translator;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Funzioni dei template: testi (t, tc), importi, asset, CSRF, paesi, impostazioni, flash, URL dei filtri.
 * Nessuna di queste restituisce HTML con dati esterni: l'autoescape resta sempre attivo.
 */
final class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Translator $translator,
        private readonly ViewContext $context,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly string $publicDir,
        private readonly CountryRepository $countries,
        private readonly AdminMenu $adminMenu,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'ctx' => $this->context,
            'locales' => Translator::LOCALES,
            'wtb_mode' => $this->config->wtbMode(),
            'app_env' => $this->config->env(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', fn (string $key, array $params = []): string => $this->translator->get($key, $params)),
            new TwigFunction('tc', fn (string $key, int $count, array $params = []): string => $this->translator->choice($key, $count, $params)),
            new TwigFunction('locale', fn (): string => $this->translator->locale()),
            new TwigFunction('money', fn (?int $cents): string => $cents === null ? '' : Money::format($cents, $this->translator->locale())),
            new TwigFunction('asset', $this->asset(...)),
            new TwigFunction('csrf_token', fn (): string => $this->context->session === null ? '' : Csrf::token($this->context->session)),
            new TwigFunction('csrf_field', fn (): Markup => new Markup(
                '<input type="hidden" hidden name="' . Csrf::FIELD . '" value="' . ($this->context->session === null ? '' : Csrf::token($this->context->session)) . '">',
                'UTF-8',
            )),
            new TwigFunction('country_options', fn (): array => $this->countries->servedOptions($this->translator->locale())),
            new TwigFunction('admin_menu', fn (): array => $this->adminMenu->items()),
            new TwigFunction('country_name', fn (?string $code): string => $code === null || $code === '' ? '' : CountryRepository::name($code, $this->translator->locale())),
            new TwigFunction('setting', fn (string $key): mixed => $this->settings->get($key)),
            new TwigFunction('flashes', $this->flashes(...)),
            new TwigFunction('old', $this->old(...)),
            new TwigFunction('field_error', $this->fieldError(...)),
            new TwigFunction('query_url', $this->queryUrl(...)),
            new TwigFunction('lang_url', $this->langUrl(...)),
            new TwigFunction('path_is', fn (string $prefix): bool => $prefix === '/' ? $this->context->path === '/' : str_starts_with($this->context->path, $prefix)),
            new TwigFunction('whatsapp_url', fn (): string => $this->settings->string('company.whatsapp') === '' ? '' : 'https://wa.me/' . $this->settings->string('company.whatsapp')),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', fn (?int $cents): string => $cents === null ? '' : Money::format($cents, $this->translator->locale())),
            new TwigFilter('money_input', static fn (?int $cents): string => $cents === null ? '' : Money::formatInput($cents)),
            new TwigFilter('local_date', $this->localDate(...)),
            new TwigFilter('local_datetime', fn (?string $iso): string => $this->localDate($iso, 'd/m/Y H:i')),
        ];
    }

    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = $this->publicDir . '/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '0';

        return '/assets/' . $path . '?v=' . $version;
    }

    /** @return list<array{type: string, message: string}> */
    public function flashes(): array
    {
        if ($this->context->flashes === null) {
            $this->context->flashes = $this->context->session?->takeFlashes() ?? [];
        }

        return $this->context->flashes;
    }

    /** Messaggio d'errore (già tradotto) per un campo del modulo, o stringa vuota. */
    public function fieldError(string $field): string
    {
        if ($this->context->errors === null) {
            $this->context->errors = $this->context->session?->takeErrors() ?? [];
        }
        $key = $this->context->errors[$field] ?? null;

        return $key === null ? '' : $this->translator->get($key);
    }

    public function old(string $field, string $default = ''): string
    {
        if ($this->context->oldInput === null) {
            $this->context->oldInput = $this->context->session?->takeOldInput() ?? [];
        }
        $value = $this->context->oldInput[$field] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * URL della pagina corrente con alcuni parametri cambiati (null li toglie); toglie sempre "page".
     *
     * @param array<string, mixed> $changes
     */
    public function queryUrl(array $changes = [], ?string $path = null): string
    {
        $query = $this->context->query;
        unset($query['page'], $query['partial'], $query['lang']);
        foreach ($changes as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return ($path ?? $this->context->path) . ($qs !== '' ? '?' . $qs : '');
    }

    public function langUrl(string $locale): string
    {
        $query = $this->context->query;
        $query['lang'] = $locale;
        unset($query['partial']);

        return $this->context->path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function localDate(?string $iso, string $format = 'd/m/Y'): string
    {
        $date = Clock::parse($iso);
        if ($date === null) {
            return '';
        }

        return $date->setTimezone(new \DateTimeZone($this->config->timezone()))->format($format);
    }
}
