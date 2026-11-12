<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/**
 * Corpo di /quote/ e /orders/: una riga per variante + regime (il tipo di stock lo assegna il fornitore).
 * Mai prezzi né note del cliente (docs/06).
 */
final class SupplierOrderRequest
{
    /**
     * @param list<array{variant_id: int, vat_regime: string, quantity: int}> $lines
     * @param array<string, string> $shipTo per il preventivo basta "country"
     */
    public function __construct(
        public readonly string $reference,
        public readonly array $lines,
        public readonly array $shipTo,
        public readonly string $paymentMethod = 'bank_transfer',
        public readonly string $notes = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toQuotePayload(): array
    {
        return [
            'lines' => $this->lines,
            'ship_to' => ['country' => strtoupper($this->shipTo['country'] ?? 'IT')],
        ];
    }

    /** @return array<string, mixed> */
    public function toOrderPayload(): array
    {
        return [
            'reference' => $this->reference,
            'lines' => $this->lines,
            'ship_to' => $this->shipTo,
            'payment_method' => $this->paymentMethod,
            'notes' => $this->notes,
        ];
    }

    public function pieces(): int
    {
        return array_sum(array_map(static fn (array $l): int => $l['quantity'], $this->lines));
    }
}
