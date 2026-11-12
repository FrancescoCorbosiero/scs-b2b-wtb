<?php

declare(strict_types=1);

namespace App\Accounts;

/**
 * Partita IVA: solo controllo di formato per paese (la verifica VIES automatica è rinviata, D-013).
 */
final class VatNumber
{
    /** Formati senza prefisso di paese. */
    private const FORMATS = [
        'AT' => 'U\d{8}', 'BE' => '[01]?\d{9}', 'BG' => '\d{9,10}', 'CY' => '\d{8}[A-Z]', 'CZ' => '\d{8,10}',
        'DE' => '\d{9}', 'DK' => '\d{8}', 'EE' => '\d{9}', 'GR' => '\d{9}', 'ES' => '[A-Z0-9]\d{7}[A-Z0-9]',
        'FI' => '\d{8}', 'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}', 'HR' => '\d{11}', 'HU' => '\d{8}',
        'IE' => '\d{7}[A-WY][A-I]?|\d[A-Z+*]\d{5}[A-W]', 'IT' => '\d{11}', 'LT' => '\d{9}|\d{12}', 'LU' => '\d{8}',
        'LV' => '\d{11}', 'MT' => '\d{8}', 'NL' => '\d{9}B\d{2}', 'PL' => '\d{10}', 'PT' => '\d{9}', 'RO' => '\d{2,10}',
        'SE' => '\d{12}', 'SI' => '\d{8}', 'SK' => '\d{10}',
        'GB' => '\d{9}|\d{12}|GD\d{3}|HA\d{3}', 'CH' => 'E\d{9}(MWST|TVA|IVA)?',
    ];

    /** Prefisso usato nei numeri IVA quando diverso dal codice ISO (Grecia). */
    private const PREFIXES = ['GR' => 'EL'];

    /** Forma normalizzata (maiuscole, senza spazi/punti/trattini, con prefisso), o null se il formato non va. */
    public static function normalize(string $input, string $country): ?string
    {
        $country = strtoupper($country);
        $prefix = self::PREFIXES[$country] ?? $country;
        $value = strtoupper((string) preg_replace('/[\s.\-\/]/', '', $input));
        foreach ([$prefix, $country] as $p) {
            if (str_starts_with($value, $p)) {
                $value = substr($value, strlen($p));
                break;
            }
        }
        $format = self::FORMATS[$country] ?? null;
        if ($format === null) {
            return strlen($value) >= 4 && strlen($value) <= 20 ? $prefix . $value : null;
        }
        if (preg_match('/^(?:' . $format . ')$/', $value) !== 1) {
            return null;
        }
        if ($country === 'IT' && !self::italianChecksum($value)) {
            return null;
        }

        return $prefix . $value;
    }

    /** Cifra di controllo della partita IVA italiana. */
    public static function italianChecksum(string $digits): bool
    {
        if (preg_match('/^\d{11}$/', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $n = (int) $digits[$i];
            if ($i % 2 === 1) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
        }

        return (10 - $sum % 10) % 10 === (int) $digits[10];
    }
}
