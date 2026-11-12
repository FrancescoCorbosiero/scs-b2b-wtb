<?php

declare(strict_types=1);

namespace App\Catalog;

/** Il sync completo si è fermato per la soglia di calo dei prodotti (WTB_SYNC_MAX_DROP_PERCENT). */
final class SyncBlockedException extends \RuntimeException
{
}
