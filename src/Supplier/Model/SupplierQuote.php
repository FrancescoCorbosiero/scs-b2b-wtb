<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/** Preventivo del fornitore: merce, spedizione, trasporto dello stock esteso, IVA, totale. Tutto RISERVATO. */
final class SupplierQuote
{
    /**
     * @param list<SupplierQuoteLine> $lines una riga per fascia (pronta consegna / stock esteso)
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $shipToCountry,
        public readonly int $pieces,
        public readonly int $subtotalCents,
        public readonly int $shippingCents,
        public readonly int $extendedTransportCents,
        public readonly int $shippingTotalCents,
        public readonly int $vatCents,
        public readonly int $totalCents,
        public readonly string $vatNote,
        public readonly array $lines,
        public readonly array $raw,
    ) {
    }

    /**
     * Paia per (sku, taglia EU, regime, tipo di stock) con il costo unitario.
     *
     * @return array<string, array{quantity: int, unit_cost_cents: int}>
     */
    public function split(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            $key = self::key($line->sku, $line->sizeEu, $line->regime, $line->stockType);
            $out[$key] = [
                'quantity' => ($out[$key]['quantity'] ?? 0) + $line->quantity,
                'unit_cost_cents' => $line->unitCostCents,
            ];
        }

        return $out;
    }

    public static function key(string $sku, string $sizeEu, string $regime, string $stockType): string
    {
        return $sku . '|' . $sizeEu . '|' . $regime . '|' . $stockType;
    }
}
