<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

use App\Supplier\Exception\SupplierAuthException;
use App\Supplier\Exception\SupplierException;
use App\Supplier\Exception\SupplierForbiddenException;
use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\Exception\SupplierNotFoundException;
use App\Supplier\Exception\SupplierRateLimitedException;
use App\Supplier\Exception\SupplierRejectedException;
use App\Supplier\Exception\SupplierUnavailableException;
use App\Supplier\Wtb\Http\Transport;
use App\Supplier\Wtb\Http\TransportException;
use App\Supplier\Wtb\Http\TransportResponse;
use App\Support\SystemFlags;
use Psr\Log\LoggerInterface;

/**
 * Client HTTP del fornitore (docs/03 § Client HTTP): chiave per modalità, limite di frequenza,
 * nuovi tentativi per tipo di chiamata, errori tipizzati. I log non contengono mai la chiave né i corpi.
 */
final class WtbClient
{
    public const FLAG_AUTH_ERROR = 'supplier.auth_error';

    /** Tentativi in più su rete/5xx: letture e preventivi 2, ordini 2 (= 3 tentativi con lo stesso reference). */
    private const RETRIES = ['read' => 2, 'quote' => 2, 'order' => 2];
    private const RETRIES_429 = 3;

    /** @var callable(float): void */
    private $sleep;

    private RequestThrottle $throttle;

    /** @param (callable(float): void)|null $sleep */
    public function __construct(
        private readonly WtbConfig $config,
        private readonly Transport $transport,
        private readonly LoggerInterface $logger,
        private readonly SystemFlags $flags,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->throttle = new RequestThrottle(100, $this->sleep);
    }

    public function config(): WtbConfig
    {
        return $this->config;
    }

    /**
     * @param array<string, string|int> $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = [], bool $interactive = false): array
    {
        return $this->request('GET', $path, $query, null, 'read', $interactive)['data'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, data: array<mixed>}
     */
    public function post(string $path, array $payload, string $kind, bool $interactive = false): array
    {
        return $this->request('POST', $path, [], $payload, $kind, $interactive);
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $payload
     * @return array{status: int, data: array<mixed>}
     */
    private function request(string $method, string $path, array $query, ?array $payload, string $kind, bool $interactive): array
    {
        if ($this->config->requireKey && $this->config->apiKey === '') {
            throw new SupplierAuthException(sprintf('Chiave del fornitore non impostata per la modalità %s (WTB_API_KEY_%s).', $this->config->mode, strtoupper($this->config->mode)));
        }
        $url = $this->config->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'SCS-B2B/1.0',
        ];
        if ($this->config->apiKey !== '') {
            $headers['X-API-Key'] = $this->config->apiKey;
        }
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $retries = self::RETRIES[$kind] ?? 2;
        $retries429 = self::RETRIES_429;
        $attempt = 0;
        while (true) {
            $attempt++;
            $this->throttle->wait();
            $started = microtime(true);
            try {
                $response = $this->transport->send($method, $url, $headers, $body, $interactive ? min(10, $this->config->timeout) : $this->config->timeout);
            } catch (TransportException $e) {
                $this->logger->warning('Fornitore: errore di rete', ['method' => $method, 'path' => $path, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                if ($retries-- > 0) {
                    ($this->sleep)($interactive ? 0.5 * $attempt : 1.0 * $attempt);
                    continue;
                }
                throw new SupplierUnavailableException('Fornitore irraggiungibile: ' . $e->getMessage(), 0, $e);
            }
            $ms = (int) round((microtime(true) - $started) * 1000);
            $this->logger->info('Fornitore: risposta', ['method' => $method, 'path' => $path, 'status' => $response->status, 'ms' => $ms, 'attempt' => $attempt]);

            if ($response->status >= 200 && $response->status < 300) {
                $this->flags->clear(self::FLAG_AUTH_ERROR);

                return ['status' => $response->status, 'data' => $this->decode($response, $path)];
            }
            if ($response->status === 429) {
                $wait = $this->retryAfter($response);
                if ($retries429-- > 0 && (!$interactive || $wait <= 5)) {
                    $this->logger->notice('Fornitore: limite di frequenza, attendo', ['seconds' => $wait]);
                    ($this->sleep)((float) $wait);
                    continue;
                }
                throw new SupplierRateLimitedException('Il fornitore limita le richieste (429): riprovare tra ' . $wait . ' secondi.');
            }
            if ($response->status >= 500) {
                if ($retries-- > 0) {
                    ($this->sleep)($interactive ? 0.5 * $attempt : 1.0 * $attempt);
                    continue;
                }
                throw new SupplierUnavailableException('Errore del fornitore (' . $response->status . ').');
            }

            throw $this->clientError($response, $path);
        }
    }

    /** @return array<mixed> */
    private function decode(TransportResponse $response, string $path): array
    {
        try {
            $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SupplierFormatException('Risposta non JSON da ' . $path . '.');
        }
        if (!is_array($data)) {
            throw new SupplierFormatException('Risposta JSON inattesa da ' . $path . '.');
        }

        return $data;
    }

    private function retryAfter(TransportResponse $response): int
    {
        $header = trim((string) $response->header('retry-after'));
        if (preg_match('/^\d+$/', $header) === 1) {
            return max(1, min(120, (int) $header));
        }
        $time = $header !== '' ? strtotime($header) : false;
        if ($time !== false) {
            return max(1, min(120, $time - time()));
        }

        return 30;
    }

    private function clientError(TransportResponse $response, string $path): SupplierException
    {
        $data = json_decode($response->body, true);
        $data = is_array($data) ? $data : [];
        $detail = is_string($data['detail'] ?? null) ? $data['detail'] : '';

        if ($response->status === 401) {
            $this->flags->setJson(self::FLAG_AUTH_ERROR, ['message' => $detail, 'path' => $path]);
            $this->logger->error('Fornitore: chiave rifiutata (401)', ['path' => $path]);

            return new SupplierAuthException('Chiave rifiutata dal fornitore (401)' . ($detail !== '' ? ': ' . $detail : '') . '.');
        }
        if ($response->status === 403) {
            return new SupplierForbiddenException('Operazione non consentita dal fornitore (403)' . ($detail !== '' ? ': ' . $detail : '') . '.');
        }
        if ($response->status === 404) {
            return new SupplierNotFoundException($detail !== '' ? $detail : 'Non trovato (404).');
        }
        if ($response->status === 400) {
            $fields = [];
            foreach ($data as $field => $value) {
                $messages = is_array($value) ? array_values(array_filter($value, 'is_string')) : (is_string($value) && $value !== '' ? [$value] : []);
                if ($messages !== []) {
                    $fields[(string) $field] = $messages;
                }
            }
            $summary = [];
            foreach ($fields as $field => $messages) {
                $summary[] = $field . ': ' . implode(' ', $messages);
            }
            $message = $summary === []
                ? 'Richiesta rifiutata dal fornitore senza dettagli (400): riga non valida o variante sconosciuta.'
                : 'Richiesta rifiutata dal fornitore: ' . implode(' · ', $summary);
            $this->logger->warning('Fornitore: richiesta rifiutata (400)', ['path' => $path, 'fields' => array_keys($fields)]);

            return new SupplierRejectedException($message, $fields);
        }

        return new SupplierException('Risposta inattesa dal fornitore (' . $response->status . ').');
    }
}
