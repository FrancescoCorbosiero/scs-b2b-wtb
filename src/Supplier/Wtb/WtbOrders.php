<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

use App\Supplier\Exception\SupplierNotFoundException;
use App\Supplier\Model\SupplierOrderData;
use App\Supplier\Model\SupplierOrderRequest;
use App\Supplier\Model\SupplierOrderResult;
use App\Supplier\Model\SupplierQuote;
use App\Supplier\SupplierOrders;

/** Preventivi e ordini presso WTB (docs/06). Idempotenza: stesso reference ⇒ stesso ordine (200). */
final class WtbOrders implements SupplierOrders
{
    public function __construct(
        private readonly WtbClient $client,
        private readonly WtbMapper $mapper,
    ) {
    }

    public function quote(SupplierOrderRequest $request, bool $interactive = false): SupplierQuote
    {
        return $this->mapper->quote($this->client->post('/quote/', $request->toQuotePayload(), 'quote', $interactive)['data']);
    }

    public function createOrder(SupplierOrderRequest $request): SupplierOrderResult
    {
        $response = $this->client->post('/orders/', $request->toOrderPayload(), 'order');

        return new SupplierOrderResult($this->mapper->order($response['data']), $response['status'] === 201, 1);
    }

    public function findByReference(string $reference): ?SupplierOrderData
    {
        $data = $this->client->get('/orders/', ['reference' => $reference]);
        foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $row) {
            if (is_array($row) && ($row['reference'] ?? null) === $reference) {
                return $this->mapper->order($row);
            }
        }

        return null;
    }

    public function getOrder(int $id): SupplierOrderData
    {
        try {
            return $this->mapper->order($this->client->get('/orders/' . $id . '/'));
        } catch (SupplierNotFoundException $e) {
            throw new SupplierNotFoundException('Ordine ' . $id . ' non trovato presso il fornitore.', 0, $e);
        }
    }

    public function listOrders(int $page = 1): array
    {
        $data = $this->client->get('/orders/', ['page' => max(1, $page)]);
        $orders = [];
        foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $row) {
            if (is_array($row)) {
                $orders[] = $this->mapper->order($row);
            }
        }

        return ['orders' => $orders, 'has_more' => ($data['has_more'] ?? false) === true];
    }

    public function mode(): string
    {
        return $this->client->config()->mode;
    }
}
