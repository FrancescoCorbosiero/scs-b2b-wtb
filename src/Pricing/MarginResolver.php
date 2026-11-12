<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Settings\SettingsService;

/**
 * Sceglie il margine di un'offerta. In questa versione vale il margine di default delle impostazioni;
 * le regole di /admin/margini si aggiungono qui (docs/04).
 */
class MarginResolver
{
    private ?MarginSpec $default = null;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /** Copia pronta per un giro di reprice (regole e default letti una volta). */
    public function withRules(): self
    {
        $copy = clone $this;
        $copy->default = MarginSpec::fromDefault(
            $this->settings->string('prices.default_margin_type'),
            $this->settings->string('prices.default_margin_value'),
        );

        return $copy;
    }

    /** @param array<string, mixed> $product riga del prodotto */
    public function resolve(array $product, string $regime, string $stockType): MarginSpec
    {
        return $this->default ?? MarginSpec::fromDefault(
            $this->settings->string('prices.default_margin_type'),
            $this->settings->string('prices.default_margin_value'),
        );
    }
}
