<?php

declare(strict_types=1);

namespace App\Supplier\Exception;

/** Fornitore irraggiungibile (rete, timeout o errore 5xx). */
final class SupplierUnavailableException extends SupplierException
{
}
