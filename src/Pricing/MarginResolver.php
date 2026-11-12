<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Settings\SettingsService;

/**
 * Sceglie il margine di un'offerta (docs/04): la prima regola attiva che combacia (SKU per prime, poi per
 * priorità), altrimenti il margine di default delle impostazioni.
 */
class MarginResolver
{
    private ?MarginSpec $default = null;

    /** @var list<MarginRule>|null */
    private ?array $rules = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MarginRuleRepository $repository,
    ) {
    }

    /** Copia pronta per un giro di reprice: regole e default letti una volta. */
    public function withRules(): self
    {
        $copy = clone $this;
        $copy->default = $this->defaultSpec();
        $copy->rules = $this->repository->all(true);

        return $copy;
    }

    public function defaultSpec(): MarginSpec
    {
        return MarginSpec::fromDefault(
            $this->settings->string('prices.default_margin_type'),
            $this->settings->string('prices.default_margin_value'),
        );
    }

    /** @param array<string, mixed> $product riga del prodotto */
    public function resolve(array $product, string $regime, string $stockType): MarginSpec
    {
        foreach ($this->rules ?? $this->repository->all(true) as $rule) {
            if ($rule->matches($product, $regime, $stockType)) {
                return $rule->spec();
            }
        }

        return $this->default ?? $this->defaultSpec();
    }

    /**
     * Stima del margine al netto dell'IVA dovuta sul margine nel regime del margine (in Italia 22/122 della
     * differenza): informativa, da validare col commercialista (docs/04).
     */
    public static function netMarginEstimate(int $saleCents, int $costCents, string $regime, int $vatRatePercent): int
    {
        $gross = $saleCents - $costCents;
        if ($regime !== 'margin' || $gross <= 0) {
            return $gross;
        }

        return intdiv($gross * 100 + intdiv(100 + $vatRatePercent, 2), 100 + $vatRatePercent);
    }
}
