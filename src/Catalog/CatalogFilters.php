<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Filtri del catalogo, tutti nella query string (URL condivisibili, docs/07).
 * In vetrina i filtri e gli ordinamenti per prezzo vengono ignorati lato server.
 */
final class CatalogFilters
{
    public const SORTS = ['rilevanza', 'nome', 'prezzo_asc', 'prezzo_desc', 'disponibilita', 'novita'];
    public const SIZE_SYSTEMS = ['eu', 'us', 'uk'];
    public const AVAILABILITY = ['alta', 'media', 'bassa'];

    /**
     * @param list<string> $brands
     * @param list<string> $lines
     * @param list<string> $genders
     * @param list<string> $sizeCategories
     * @param list<string> $sizes
     */
    public function __construct(
        public readonly string $q = '',
        public readonly array $brands = [],
        public readonly array $lines = [],
        public readonly array $genders = [],
        public readonly array $sizeCategories = [],
        public readonly string $category = '',
        public readonly array $sizes = [],
        public readonly string $regime = '',
        public readonly bool $immediateOnly = false,
        public readonly ?int $priceMinCents = null,
        public readonly ?int $priceMaxCents = null,
        public readonly string $availability = '',
        public readonly bool $availableOnly = false,
        public readonly bool $featuredOnly = false,
        public readonly string $sort = 'rilevanza',
        public readonly int $page = 1,
        public readonly string $sizeSystem = 'eu',
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, bool $withPrices, string $sizeSystem = 'eu'): self
    {
        $list = static function (string $key) use ($query): array {
            $value = $query[$key] ?? [];
            $values = is_array($value) ? $value : [$value];
            $out = [];
            foreach ($values as $v) {
                if (is_scalar($v) && trim((string) $v) !== '' && mb_strlen((string) $v) <= 120) {
                    $out[] = trim((string) $v);
                }
            }

            return array_values(array_unique(array_slice($out, 0, 50)));
        };
        $str = static fn (string $key, int $max = 120): string => is_scalar($query[$key] ?? null) ? mb_substr(trim((string) $query[$key]), 0, $max) : '';
        $flag = static fn (string $key): bool => in_array($str($key), ['1', 'on', 'true', 'si'], true);
        $euro = static function (string $key) use ($str): ?int {
            $value = str_replace(',', '.', $str($key, 12));

            return preg_match('/^\d{1,6}(\.\d{1,2})?$/', $value) === 1 ? \App\Support\Money::parse($value) : null;
        };
        $sort = $str('ordina');
        if (!in_array($sort, self::SORTS, true) || (!$withPrices && str_starts_with($sort, 'prezzo'))) {
            $sort = 'rilevanza';
        }
        $regime = $str('regime');
        $availability = $str('disp');
        $system = $str('sistema');

        return new self(
            q: $str('q', 100),
            brands: $list('brand'),
            lines: $list('linea'),
            genders: array_values(array_intersect($list('genere'), ['men', 'women', 'kids', 'unisex'])),
            sizeCategories: array_values(array_intersect($list('eta'), SizeCategory::ALL)),
            category: $str('cat', 300),
            sizes: $list('taglie'),
            regime: in_array($regime, ['margin', 'ordinary'], true) ? $regime : '',
            immediateOnly: $flag('pronta'),
            priceMinCents: $withPrices ? $euro('prezzo_min') : null,
            priceMaxCents: $withPrices ? $euro('prezzo_max') : null,
            availability: in_array($availability, self::AVAILABILITY, true) ? $availability : '',
            availableOnly: $flag('disponibili'),
            featuredOnly: $flag('evidenza'),
            sort: $sort,
            page: max(1, min(200, (int) $str('page', 4))),
            sizeSystem: in_array($system, self::SIZE_SYSTEMS, true) ? $system : (in_array($sizeSystem, self::SIZE_SYSTEMS, true) ? $sizeSystem : 'eu'),
        );
    }

    public function isFiltered(): bool
    {
        return $this->q !== '' || $this->brands !== [] || $this->lines !== [] || $this->genders !== [] || $this->sizeCategories !== []
            || $this->category !== '' || $this->sizes !== [] || $this->regime !== '' || $this->immediateOnly
            || $this->priceMinCents !== null || $this->priceMaxCents !== null || $this->availability !== ''
            || $this->availableOnly || $this->featuredOnly;
    }

    /** Parametri per ricostruire l'URL (senza pagina). @return array<string, string|list<string>> */
    public function toQuery(): array
    {
        $q = array_filter([
            'q' => $this->q,
            'brand' => $this->brands,
            'linea' => $this->lines,
            'genere' => $this->genders,
            'eta' => $this->sizeCategories,
            'cat' => $this->category,
            'taglie' => $this->sizes,
            'regime' => $this->regime,
            'pronta' => $this->immediateOnly ? '1' : '',
            'prezzo_min' => $this->priceMinCents === null ? '' : (string) intdiv($this->priceMinCents, 100),
            'prezzo_max' => $this->priceMaxCents === null ? '' : (string) intdiv($this->priceMaxCents, 100),
            'disp' => $this->availability,
            'disponibili' => $this->availableOnly ? '1' : '',
            'evidenza' => $this->featuredOnly ? '1' : '',
            'ordina' => $this->sort === 'rilevanza' ? '' : $this->sort,
        ], static fn ($v): bool => $v !== '' && $v !== []);

        return $q;
    }
}
