<?php

declare(strict_types=1);

namespace App\Supplier\Exception;

/** Ordini al fornitore spenti (kill switch WTB_ORDERS_ENABLED=0) o bloccati da un controllo (tetto di spesa, modalità). */
final class SupplierDisabledException extends SupplierException
{
}
