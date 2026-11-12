<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/** Ordine presso il fornitore (risposta di /orders/, dettaglio, elenco, dati dei webhook). Importi = costi, RISERVATI. */
final class SupplierOrderData
{
    public const STATUSES = ['received', 'confirmed', 'paid', 'shipped', 'cancelled'];

    /**
     * @param list<array{sku: string, name: string, size_eu: string, vat_regime: string, quantity: int, unit_cost_cents: int}> $lines
     * @param list<array{carrier: string, number: string, url: string}> $tracking
     * @param array<string, mixed>|null $paymentInstructions forma libera (M0: in prova arriva null)
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $number,
        public readonly string $reference,
        public readonly string $status,
        public readonly bool $isPaid,
        public readonly string $paymentMethod,
        public readonly int $subtotalCents,
        public readonly int $shippingCents,
        public readonly int $vatCents,
        public readonly int $totalCents,
        public readonly array $lines,
        public readonly array $tracking,
        public readonly ?array $paymentInstructions,
        public readonly bool $isTest,
        public readonly string $createdAt,
        public readonly array $raw,
    ) {
    }
}
