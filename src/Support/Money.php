<?php

declare(strict_types=1);

namespace App\Support;

use NumberFormatter;

/**
 * Soldi in centesimi interi, mai float (regola d'oro 7).
 * Gli arrotondamenti dei prezzi stanno solo in App\Pricing\Rounding.
 */
final class Money
{
    /**
     * Converte un importo decimale in stringa ("63.84", "0", "12.5") in centesimi, senza passare da float.
     */
    public static function parse(string $decimal): int
    {
        $value = trim($decimal);
        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            throw new \InvalidArgumentException('Importo non valido: ' . $value);
        }
        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /**
     * Legge un importo scritto da una persona: "15", "15,5", "15,50", "1.234,56", "15.50".
     */
    public static function parseInput(string $input): ?int
    {
        $value = str_replace([' ', '€', "\u{00a0}"], '', trim($input));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value) === 1) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        return self::parse($value);
    }

    /** Centesimi → stringa decimale con punto ("63.84"). */
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** Centesimi → importo per la persona ("1.234,56 €" in italiano, "€1,234.56" in inglese). */
    public static function format(int $cents, string $locale = 'it'): string
    {
        static $formatters = [];
        $key = $locale === 'en' ? 'en_IE' : 'it_IT';
        $formatters[$key] ??= new NumberFormatter($key, NumberFormatter::CURRENCY);
        $formatted = $formatters[$key]->formatCurrency(self::toFloatForDisplay($cents), 'EUR');

        return $formatted === false ? self::toDecimal($cents) . ' €' : $formatted;
    }

    /** Importo per un campo di modulo ("15,00" in italiano). */
    public static function formatInput(int $cents): string
    {
        return str_replace('.', ',', self::toDecimal($cents));
    }

    /** Importo in euro come numero per le celle Excel (solo uscita: nessun calcolo dopo). */
    public static function toExportNumber(int $cents): float
    {
        return self::toFloatForDisplay($cents);
    }

    /**
     * Unico punto in cui un importo diventa float: solo per la formattazione a video o nell'export,
     * su valori già interi in centesimi (nessun calcolo).
     */
    private static function toFloatForDisplay(int $cents): float
    {
        return $cents / 100;
    }
}
