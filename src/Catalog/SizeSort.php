<?php

declare(strict_types=1);

namespace App\Catalog;

/** Chiave numerica per ordinare le taglie EU scritte come testo ("38.5", "36 2/3"). */
final class SizeSort
{
    public static function key(string $size): int
    {
        $size = trim(str_replace(',', '.', $size));
        if (preg_match('/^(\d+(?:\.\d+)?)(?:\s+(\d+)\/(\d+))?/', $size, $m) !== 1) {
            return 999000;
        }
        $value = (int) round((float) $m[1] * 1000);
        if (isset($m[2], $m[3]) && (int) $m[3] > 0) {
            $value += intdiv((int) $m[2] * 1000, (int) $m[3]);
        }

        return $value;
    }
}
