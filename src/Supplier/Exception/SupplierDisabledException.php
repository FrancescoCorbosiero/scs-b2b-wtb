<?php

declare(strict_types=1);

namespace App\Supplier\Exception;

/** Ordini al fornitore spenti (impostazione supplier.orders_enabled spenta) o bloccati da un controllo (tetto di spesa, modalità). */
final class SupplierDisabledException extends SupplierException
{
}
