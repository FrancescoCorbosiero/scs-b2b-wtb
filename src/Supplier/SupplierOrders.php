<?php

declare(strict_types=1);

namespace App\Supplier;

use App\Supplier\Model\SupplierOrderData;
use App\Supplier\Model\SupplierOrderRequest;
use App\Supplier\Model\SupplierOrderResult;
use App\Supplier\Model\SupplierQuote;

/** Preventivi e ordini presso il fornitore. */
interface SupplierOrders
{
    /** @param bool $interactive true durante una richiesta del cliente: niente attese lunghe */
    public function quote(SupplierOrderRequest $request, bool $interactive = false): SupplierQuote;

    /** Crea l'ordine; i nuovi tentativi riusano lo stesso reference (idempotente). */
    public function createOrder(SupplierOrderRequest $request): SupplierOrderResult;

    public function findByReference(string $reference): ?SupplierOrderData;

    public function getOrder(int $id): SupplierOrderData;

    /** @return array{orders: list<SupplierOrderData>, has_more: bool} */
    public function listOrders(int $page = 1): array;

    /** Modalità in cui lavora (fixture, test, live): gli ordini la ricordano. */
    public function mode(): string;
}
