<?php

declare(strict_types=1);

namespace App\Supplier\Wtb\Http;

/** Errore di rete o timeout (la richiesta potrebbe essere arrivata o no). */
final class TransportException extends \RuntimeException
{
}
