<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

use App\Support\Config;

/**
 * Configurazione del fornitore dal .env (docs/03); "tutto il catalogo" arriva dall'impostazione catalog.supplier_all (D-021). La chiave si sceglie in base alla modalità e viaggia
 * solo verso WTB_BASE_URL. In sviluppo una chiave vuota non si invia (la aggiunge il proxy di Claude Code web);
 * in produzione una chiave vuota è un errore bloccante.
 */
final class WtbConfig
{
    /**
     * @param list<string> $imageHosts
     * @param bool|\Closure(): bool $catalogAll tutto il catalogo (all=1); una closure si rilegge a ogni chiamata
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly bool $requireKey,
        public readonly int $timeout,
        private readonly bool|\Closure $catalogAll,
        public readonly array $imageHosts,
        public readonly bool $imageSendKey,
        public readonly string $webhookSecret,
        public readonly string $fixturesDir,
        public readonly int $maxDropPercent,
    ) {
    }

    /** @param bool|\Closure(): bool $catalogAll */
    public static function fromConfig(Config $config, bool|\Closure $catalogAll = false): self
    {
        $mode = $config->wtbMode();
        $key = match ($mode) {
            'test' => $config->string('WTB_API_KEY_TEST'),
            'live' => $config->string('WTB_API_KEY_LIVE'),
            default => '',
        };

        return new self(
            $mode,
            rtrim($config->string('WTB_BASE_URL', 'https://b2b.wtbsnkrs.it/api/v1'), '/'),
            $key,
            $mode !== 'fixture' && $config->isProduction(),
            max(3, $config->int('WTB_HTTP_TIMEOUT', 20)),
            $catalogAll,
            array_map('strtolower', $config->list('WTB_IMAGE_HOSTS', 'b2b.wtbsnkrs.it')),
            $config->bool('WTB_IMAGE_SEND_KEY'),
            $config->string('WTB_WEBHOOK_SECRET'),
            $config->root() . '/fixtures/wtb',
            min(100, max(0, $config->int('WTB_SYNC_MAX_DROP_PERCENT', 50))),
        );
    }

    /** Tutto il catalogo del fornitore (all=1) o solo la selezione fatta nel suo portale. */
    public function catalogAll(): bool
    {
        return $this->catalogAll instanceof \Closure ? (bool) ($this->catalogAll)() : $this->catalogAll;
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
    }

    /** Indirizzo del portale del fornitore (per i promemoria in admin, es. annullare un ordine). */
    public function portalUrl(): string
    {
        $scheme = (string) parse_url($this->baseUrl, PHP_URL_SCHEME);

        return ($scheme === '' ? 'https' : $scheme) . '://' . $this->host() . '/';
    }
}
