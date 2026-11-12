<?php

declare(strict_types=1);

namespace App\Supplier\Wtb\Fixture;

use App\Supplier\Wtb\Http\Transport;
use App\Supplier\Wtb\Http\TransportException;
use App\Supplier\Wtb\Http\TransportResponse;

/**
 * WTB_MODE=fixture: risponde con i file di fixtures/wtb/ senza rete (docs/03 § Fixture).
 * Preventivi e ordini li calcola il Simulator con le regole viste in M0.
 * I test possono accodare risposte o errori con queue() per provare tentativi e casi limite.
 */
final class FixtureTransport implements Transport
{
    /** @var list<array{method: string, path: string, response: TransportResponse|\Throwable}> */
    private array $queued = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    private ?Simulator $simulator = null;

    public function __construct(private readonly string $directory)
    {
    }

    /** Risposta (o eccezione) da restituire alla prossima chiamata che combacia. */
    public function queue(string $method, string $pathPrefix, TransportResponse|\Throwable $response): void
    {
        $this->queued[] = ['method' => strtoupper($method), 'path' => $pathPrefix, 'response' => $response];
    }

    public function simulator(): Simulator
    {
        return $this->simulator ??= new Simulator($this->load('stock.json'), $this->load('shipping.json'), $this->load('order-created.json'));
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): TransportResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = (string) preg_replace('#^.*/api/v1#', '', $path);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach ($this->queued as $i => $item) {
            if ($item['method'] === strtoupper($method) && str_starts_with($path, $item['path'])) {
                array_splice($this->queued, $i, 1);
                if ($item['response'] instanceof \Throwable) {
                    throw $item['response'] instanceof TransportException ? $item['response'] : new TransportException($item['response']->getMessage());
                }

                return $item['response'];
            }
        }

        $payload = $body === null ? [] : (array) json_decode($body, true);

        return match (true) {
            $method === 'GET' && $path === '/shipping/' => TransportResponse::json(200, $this->load('shipping.json')),
            $method === 'GET' && $path === '/stock/' => TransportResponse::json(200, $this->load('stock.json')),
            $method === 'GET' && $path === '/products/' => $this->products($query),
            $method === 'GET' && preg_match('#^/products/([^/]+)/$#', $path, $m) === 1 => $this->product(rawurldecode($m[1])),
            $method === 'POST' && $path === '/quote/' => $this->simulator()->quote($payload),
            $method === 'POST' && $path === '/orders/' => $this->simulator()->order($payload),
            $method === 'GET' && $path === '/orders/' => $this->orders($query),
            $method === 'GET' && preg_match('#^/orders/(\d+)/$#', $path, $m) === 1 => $this->order((int) $m[1]),
            default => TransportResponse::json(404, ['detail' => 'Not found.']),
        };
    }

    /** @return array<mixed> */
    public function load(string $file): array
    {
        $path = $this->directory . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException('Fixture mancante: ' . $file);
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $query */
    private function products(array $query): TransportResponse
    {
        if (isset($query['sku']) && is_string($query['sku'])) {
            $wanted = array_flip(array_map('trim', explode(',', $query['sku'])));
            $found = array_values(array_filter($this->allProducts(), static fn (array $p): bool => isset($wanted[$p['sku']])));

            return TransportResponse::json(200, ['count' => count($found), 'page' => 1, 'page_size' => 50, 'has_more' => false, 'currency' => 'EUR', 'results' => $found]);
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $file = 'products-page-' . $page . '.json';
        if (!is_file($this->directory . '/' . $file)) {
            return TransportResponse::json(404, ['detail' => 'Pagina non valida.']);
        }

        return TransportResponse::json(200, $this->load($file));
    }

    private function product(string $sku): TransportResponse
    {
        foreach ($this->allProducts() as $product) {
            if ($product['sku'] === $sku) {
                return TransportResponse::json(200, $product);
            }
        }

        return TransportResponse::json(404, $this->load('errors/404-product.json'));
    }

    /** @param array<string, mixed> $query */
    private function orders(array $query): TransportResponse
    {
        $list = $this->load('orders-list.json');
        if (isset($query['reference'])) {
            $list['results'] = array_values(array_filter(
                is_array($list['results'] ?? null) ? $list['results'] : [],
                static fn ($o): bool => is_array($o) && ($o['reference'] ?? null) === $query['reference'],
            ));
            $list['count'] = count($list['results']);
        }

        return TransportResponse::json(200, $list);
    }

    private function order(int $id): TransportResponse
    {
        $order = $this->load('order-detail.json');
        if (($order['id'] ?? null) !== $id) {
            return TransportResponse::json(404, $this->load('errors/404-order.json'));
        }

        return TransportResponse::json(200, $order);
    }

    /** @return list<array<string, mixed>> */
    private function allProducts(): array
    {
        $all = [];
        foreach (glob($this->directory . '/products-*.json') ?: [] as $file) {
            $data = $this->load(basename($file));
            foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $product) {
                if (is_array($product)) {
                    $all[] = $product;
                }
            }
        }

        return $all;
    }
}
