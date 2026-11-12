<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsService;
use App\Support\Session;

/**
 * Dati comuni di /catalogo e /vetrina: stessi template e filtri, ma in vetrina niente prezzi nei dati,
 * niente filtri/ordinamenti per prezzo, niente carrello né export (docs/07).
 */
final class CatalogPage
{
    public const DENSITIES = ['comoda', 'compatta'];

    public function __construct(
        private readonly CatalogQuery $query,
        private readonly CatalogPresenter $presenter,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Preferenze di visualizzazione salvate in sessione (?sistema=eu|us|uk, ?densita=comoda|compatta).
     *
     * @param array<string, mixed> $query
     * @return array{size_system: string, density: string}
     */
    public function preferences(Session $session, array $query): array
    {
        $system = is_string($query['sistema'] ?? null) && in_array($query['sistema'], CatalogFilters::SIZE_SYSTEMS, true)
            ? $query['sistema'] : (string) $session->get('size_system', 'eu');
        $density = is_string($query['densita'] ?? null) && in_array($query['densita'], self::DENSITIES, true)
            ? $query['densita'] : (string) $session->get('density', 'comoda');
        $system = in_array($system, CatalogFilters::SIZE_SYSTEMS, true) ? $system : 'eu';
        $density = in_array($density, self::DENSITIES, true) ? $density : 'comoda';
        $session->set('size_system', $system);
        $session->set('density', $density);

        return ['size_system' => $system, 'density' => $density];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function index(array $query, bool $showPrices, string $basePath, array $prefs, bool $partial): array
    {
        $filters = CatalogFilters::fromQuery($query, $showPrices, $prefs['size_system']);
        $perPage = $this->settings->int('catalog.per_page');
        // Senza JavaScript "Carica altri" riapre la pagina con più prodotti; con JavaScript si caricano solo i nuovi.
        $offset = $partial ? ($filters->page - 1) * $perPage : 0;
        $limit = $partial ? $perPage : $filters->page * $perPage;
        $result = $this->query->search($filters, $offset, $limit);
        $nextQuery = $filters->toQuery() + ['page' => (string) ($filters->page + 1)];

        return [
            'filters' => $filters,
            'cards' => $this->presenter->cards($result['rows'], $showPrices, $prefs['size_system']),
            'total' => $result['total'],
            'has_more' => $result['has_more'],
            'next_url' => $basePath . '?' . http_build_query($nextQuery, '', '&', PHP_QUERY_RFC3986),
            'facets' => $partial ? null : $this->query->facets($prefs['size_system']),
            'show_prices' => $showPrices,
            'base_path' => $basePath,
            'size_system' => $prefs['size_system'],
            'density' => $prefs['density'],
            'sorts' => array_values(array_filter(CatalogFilters::SORTS, static fn (string $s): bool => $showPrices || !str_starts_with($s, 'prezzo'))),
        ];
    }

    /** @return array<string, mixed>|null */
    public function product(string $sku, bool $showPrices, string $basePath, array $prefs): ?array
    {
        $detail = $this->presenter->detail($sku, $showPrices, $prefs['size_system']);
        if ($detail === null) {
            return null;
        }

        return [
            'product' => $detail,
            'show_prices' => $showPrices,
            'base_path' => $basePath,
            'size_system' => $prefs['size_system'],
            'sell_extended' => $this->settings->bool('catalog.sell_extended'),
        ];
    }
}
