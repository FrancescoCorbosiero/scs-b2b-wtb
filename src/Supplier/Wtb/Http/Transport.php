<?php

declare(strict_types=1);

namespace App\Supplier\Wtb\Http;

/** Trasporto delle richieste verso il fornitore: curl (test/live) o fixture (sviluppo e test automatici). */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @throws TransportException errore di rete o timeout
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): TransportResponse;
}
