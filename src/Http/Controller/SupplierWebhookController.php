<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Orders\WebhookProcessor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /webhooks/wtb (docs/06 § Webhook): rotta pubblica fuori da sessione e CSRF, firmata.
 * Risponde subito (2xx entro 10 secondi): le email partono dopo la risposta (Deferred, public/index.php).
 */
final class SupplierWebhookController
{
    public function __construct(private readonly WebhookProcessor $processor)
    {
    }

    public function receive(Request $request, Response $response): Response
    {
        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && (int) $declared > WebhookProcessor::MAX_BYTES) {
            return $this->reply($response, 413, 'too_large');
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = '';
        while (!$body->eof() && strlen($raw) <= WebhookProcessor::MAX_BYTES) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        $result = $this->processor->handle($headers, $raw);

        return $this->reply($response, $result['status'], $result['outcome']);
    }

    private function reply(Response $response, int $status, string $outcome): Response
    {
        $response->getBody()->write((string) json_encode(['ok' => $status < 300, 'outcome' => $outcome]));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
