<?php

declare(strict_types=1);

namespace App\Supplier\Exception;

/** 429: troppe richieste, il fornitore ha chiesto di aspettare. */
final class SupplierRateLimitedException extends SupplierException
{
}
