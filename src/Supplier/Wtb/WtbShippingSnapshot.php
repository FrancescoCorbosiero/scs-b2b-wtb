<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\ShippingSnapshot;
use App\Support\Db;

/** Legge l'ultima risposta di /shipping/ salvata in wtb_snapshots e la traduce nel modello interno. */
final class WtbShippingSnapshot implements ShippingSnapshot
{
    public function __construct(
        private readonly Db $db,
        private readonly WtbMapper $mapper,
    ) {
    }

    public function latest(): ?array
    {
        $row = $this->db->one("SELECT payload, fetched_at FROM wtb_snapshots WHERE kind = 'shipping' ORDER BY id DESC LIMIT 1");
        $data = $row === null ? null : json_decode((string) $row['payload'], true);
        if (!is_array($data)) {
            return null;
        }
        try {
            return ['profile' => $this->mapper->shipping($data), 'fetched_at' => (string) $row['fetched_at']];
        } catch (SupplierFormatException) {
            return null;
        }
    }
}
