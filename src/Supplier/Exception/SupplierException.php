<?php

declare(strict_types=1);

namespace App\Supplier\Exception;

/** Errore del fornitore. Il messaggio è per l'admin e i log, mai per il cliente. */
class SupplierException extends \RuntimeException
{
}
