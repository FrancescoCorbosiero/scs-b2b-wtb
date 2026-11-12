<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\Model\ShippingProfile;
use App\Supplier\Model\StockFeed;
use App\Supplier\SupplierCatalog;

/** Catalogo, stock e tariffe dal fornitore WTB (docs/03). */
final class WtbCatalog implements SupplierCatalog
{
    private const MAX_PAGES = 500;

    public function __construct(
        private readonly WtbClient $client,
        private readonly WtbMapper $mapper,
    ) {
    }

    public function shippingProfile(): ShippingProfile
    {
        return $this->mapper->shipping($this->client->get('/shipping/'));
    }

    public function allProducts(): array
    {
        $this->mapper->resetWarnings();

        return $this->paged([]);
    }

    public function productsBySku(array $skus): array
    {
        $this->mapper->resetWarnings();
        $out = [];
        foreach (array_chunk(array_values(array_unique($skus)), 40) as $chunk) {
            foreach ($this->paged(['sku' => implode(',', $chunk)]) as $product) {
                $out[] = $product;
            }
        }

        return $out;
    }

    public function stock(): StockFeed
    {
        $this->mapper->resetWarnings();
        $query = $this->client->config()->catalogAll ? ['all' => 1] : [];
        $data = $this->client->get('/stock/', $query);
        $rows = $this->mapper->stock($data);
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        // Impronta del contenuto (M0: updated_at è l'ora della risposta e cambia sempre).
        $hash = hash('sha256', (string) json_encode($results));
        $complete = !is_int($data['count'] ?? null) || $data['count'] === count($results);

        return new StockFeed($rows, $hash, $complete);
    }

    public function warnings(): array
    {
        return $this->mapper->warnings();
    }

    /**
     * Tutte le pagine di /products/ seguendo solo "has_more" (M0: "count" non è affidabile e le pagine
     * possono essere più corte di page_size).
     *
     * @param array<string, string|int> $query
     * @return list<\App\Supplier\Model\SupplierProduct>
     */
    private function paged(array $query): array
    {
        $query += ['page_size' => 100];
        if ($this->client->config()->catalogAll) {
            $query['all'] = 1;
        }
        $products = [];
        $seen = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $this->mapper->productsPage($this->client->get('/products/', $query + ['page' => $page]));
            foreach ($result['products'] as $product) {
                if (isset($seen[$product->sku])) {
                    $this->mapper->warn($product->sku . ': ripetuto in più pagine, tengo la prima.');
                    continue;
                }
                $seen[$product->sku] = true;
                $products[] = $product;
            }
            if (!$result['has_more']) {
                return $products;
            }
        }
        throw new SupplierFormatException('Troppe pagine di /products/: interrotto.');
    }
}
