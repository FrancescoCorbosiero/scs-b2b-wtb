<?php

declare(strict_types=1);

namespace App\Tax;

/** Esito del calcolo IVA di un ordine: schema, aliquota, imponibile, IVA, totali e diciture (chiavi di lang/). */
final class TaxResult
{
    /** @param list<string> $legends chiavi di traduzione delle diciture (tax.legend.*) */
    public function __construct(
        public readonly string $scheme,
        public readonly int $vatRateBp,
        public readonly int $subtotalMarginCents,
        public readonly int $subtotalOrdinaryCents,
        public readonly int $shippingCents,
        public readonly int $taxableCents,
        public readonly int $vatCents,
        public readonly int $totalCents,
        public readonly array $legends,
    ) {
    }

    /** @return array<string, int|string|list<string>> */
    public function toArray(): array
    {
        return [
            'scheme' => $this->scheme,
            'vat_rate_bp' => $this->vatRateBp,
            'subtotal_margin_cents' => $this->subtotalMarginCents,
            'subtotal_ordinary_cents' => $this->subtotalOrdinaryCents,
            'shipping_cents' => $this->shippingCents,
            'taxable_cents' => $this->taxableCents,
            'vat_cents' => $this->vatCents,
            'total_cents' => $this->totalCents,
            'legends' => $this->legends,
        ];
    }
}
