<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Categoria di taglia (docs/03): adulto, GS (ragazzo) o PS (bambino, anche toddler).
 * M0: la sigla nel nome è il segnale più affidabile, poi i suffissi delle taglie US (Y = GS, C = PS).
 */
final class SizeCategory
{
    public const ADULT = 'adult';
    public const GS = 'gs';
    public const PS = 'ps';
    public const ALL = [self::ADULT, self::GS, self::PS];

    /** @param list<string> $usSizes */
    public static function classify(string $gender, string $name, array $usSizes): string
    {
        if ($gender !== 'kids') {
            return self::ADULT;
        }
        if (preg_match('/\(\s*GS\s*\)/i', $name) === 1) {
            return self::GS;
        }
        if (preg_match('/\(\s*(PS|TD)\s*\)/i', $name) === 1) {
            return self::PS;
        }
        $y = 0;
        $c = 0;
        foreach ($usSizes as $size) {
            $size = strtoupper(trim($size));
            if (str_ends_with($size, 'Y')) {
                $y++;
            } elseif (str_ends_with($size, 'C')) {
                $c++;
            }
        }
        if ($c > $y) {
            return self::PS;
        }

        return self::GS;
    }
}
