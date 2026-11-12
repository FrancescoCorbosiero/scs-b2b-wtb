<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Support\Db;

/** Paesi serviti (tabella countries): zona di spedizione e appartenenza alla UE. */
final class CountryRepository
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, array<string, mixed>> codice => riga */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->all('SELECT * FROM countries ORDER BY sort_order, code') as $row) {
                $this->cache[(string) $row['code']] = $row;
            }
        }

        return $this->cache;
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        return $this->all()[strtoupper($code)] ?? null;
    }

    public function isServed(string $code): bool
    {
        $row = $this->find($code);

        return $row !== null && (int) $row['served'] === 1;
    }

    public function isEu(string $code): bool
    {
        $row = $this->find($code);

        return $row !== null && (int) $row['is_eu'] === 1;
    }

    public function zoneOf(string $code): ?string
    {
        $row = $this->find($code);

        return $row === null ? null : (string) $row['zone'];
    }

    /**
     * Paesi serviti per i selettori, con il nome nella lingua richiesta, Italia in testa.
     *
     * @return array<string, string> codice => nome
     */
    public function servedOptions(string $locale): array
    {
        $out = [];
        $rows = array_filter($this->all(), static fn (array $r): bool => (int) $r['served'] === 1);
        $top = [];
        foreach ($rows as $code => $row) {
            $name = self::name($code, $locale);
            if ((int) $row['sort_order'] < 100) {
                $top[$code] = $name;
            } else {
                $out[$code] = $name;
            }
        }
        $collator = new \Collator($locale === 'en' ? 'en' : 'it');
        uasort($out, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $top + $out;
    }

    public static function name(string $code, string $locale): string
    {
        $name = \Locale::getDisplayRegion('-' . strtoupper($code), $locale === 'en' ? 'en' : 'it');

        return $name === '' ? strtoupper($code) : $name;
    }

    public function setZone(string $code, string $zone, bool $served): void
    {
        $this->db->update('countries', ['zone' => $zone, 'served' => $served ? 1 : 0], 'code = :c', ['c' => strtoupper($code)]);
        $this->cache = null;
    }

    public function clearCache(): void
    {
        $this->cache = null;
    }
}
