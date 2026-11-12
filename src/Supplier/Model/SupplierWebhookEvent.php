<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/** Evento in arrivo dal fornitore, già verificato e tradotto nei modelli interni. */
final class SupplierWebhookEvent
{
    public const PING = 'ping';

    public function __construct(
        public readonly string $deliveryId,
        public readonly string $event,
        public readonly string $createdAt,
        /** null per ping ed eventi senza ordine */
        public readonly ?SupplierOrderData $order,
    ) {
    }

    public function isPing(): bool
    {
        return $this->event === self::PING;
    }
}
