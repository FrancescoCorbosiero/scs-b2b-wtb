<?php

declare(strict_types=1);

namespace App\Tax;

use App\Pricing\Rounding;

/**
 * IVA per regime e paese di fatturazione (docs/04 § IVA — PROPOSTA TECNICA da validare col commercialista, D-007).
 * Servizio puro: unico posto delle regole IVA verso i clienti.
 *
 * | Paese del cliente      | Righe a margine                  | Righe ordinarie                         |
 * | Italia                 | prezzo finale, IVA non esposta   | netto + IVA tax.vat_rate_it             |
 * | Altro Stato UE (P.IVA) | prezzo finale, IVA non esposta   | netto, IVA 0% (art. 41 D.L. 331/1993)   |
 * | Regno Unito, Svizzera  | prezzo finale, IVA non esposta   | netto, IVA 0% (art. 8 D.P.R. 633/1972)  |
 *
 * Spedizione: riga a sé col trattamento di tax.shipping_regime (ordinary = netta + IVA secondo la tabella;
 * final = importo finale senza IVA esposta).
 */
final class TaxPolicy
{
    public const SCHEMES = ['domestic', 'intra_eu', 'export'];

    public static function scheme(string $billingCountry, bool $isEu): string
    {
        if (strtoupper($billingCountry) === 'IT') {
            return 'domestic';
        }

        return $isEu ? 'intra_eu' : 'export';
    }

    public static function calculate(
        string $scheme,
        int $vatRateItPercent,
        string $shippingRegime,
        int $subtotalMarginCents,
        int $subtotalOrdinaryCents,
        int $shippingCents,
    ): TaxResult {
        if (!in_array($scheme, self::SCHEMES, true)) {
            throw new \InvalidArgumentException('Schema IVA sconosciuto: ' . $scheme);
        }
        $rateBp = $scheme === 'domestic' ? $vatRateItPercent * 100 : 0;
        $taxable = $subtotalOrdinaryCents + ($shippingRegime === 'final' ? 0 : $shippingCents);
        $vat = $rateBp === 0 ? 0 : Rounding::percentHalfUp($taxable, $rateBp);

        $legends = [];
        if ($subtotalMarginCents > 0) {
            $legends[] = 'tax.legend.margin';
        }
        $hasOrdinary = $subtotalOrdinaryCents > 0 || ($shippingRegime !== 'final' && $shippingCents > 0);
        if ($hasOrdinary && $scheme === 'intra_eu') {
            $legends[] = 'tax.legend.intra_eu';
        } elseif ($hasOrdinary && $scheme === 'export') {
            $legends[] = 'tax.legend.export';
        }

        return new TaxResult(
            $scheme,
            $rateBp,
            $subtotalMarginCents,
            $subtotalOrdinaryCents,
            $shippingCents,
            $taxable,
            $vat,
            $subtotalMarginCents + $subtotalOrdinaryCents + $shippingCents + $vat,
            $legends,
        );
    }
}
