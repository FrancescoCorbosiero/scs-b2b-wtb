<?php

declare(strict_types=1);

namespace Tests\Unit\Supplier;

use App\Supplier\Exception\SupplierAuthException;
use App\Supplier\Exception\SupplierRateLimitedException;
use App\Supplier\Exception\SupplierRejectedException;
use App\Supplier\Exception\SupplierUnavailableException;
use App\Supplier\Wtb\Http\Transport;
use App\Supplier\Wtb\Http\TransportException;
use App\Supplier\Wtb\Http\TransportResponse;
use App\Supplier\Wtb\WtbClient;
use App\Supplier\Wtb\WtbConfig;
use App\Support\Db;
use App\Support\FrozenClock;
use App\Support\SystemFlags;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WtbClientTest extends TestCase
{
    /** @var list<TransportResponse|\Throwable> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>}> */
    private array $sent = [];

    /** @var list<float> */
    private array $sleeps = [];

    private SystemFlags $flags;

    private function client(string $key = '', bool $requireKey = false, string $mode = 'test'): WtbClient
    {
        $db = Db::open(':memory:');
        $db->pdo()->exec('CREATE TABLE system_flags (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->flags = new SystemFlags($db, new FrozenClock());
        $transport = new class ($this) implements Transport {
            public function __construct(private readonly WtbClientTest $test)
            {
            }

            public function send(string $method, string $url, array $headers, ?string $body, int $timeout): TransportResponse
            {
                return $this->test->next($method, $url, $headers);
            }
        };
        $config = new WtbConfig($mode, 'https://supplier.example/api/v1', $key, $requireKey, 20, false, ['supplier.example'], false, '', '/tmp', 50);

        return new WtbClient($config, $transport, new NullLogger(), $this->flags, function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    /** @param array<string, string> $headers */
    public function next(string $method, string $url, array $headers): TransportResponse
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        $response = array_shift($this->responses) ?? TransportResponse::json(200, []);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    public function testKeyHeaderOnlyWhenConfigured(): void
    {
        $client = $this->client('');
        $client->get('/shipping/');
        self::assertArrayNotHasKey('X-API-Key', $this->sent[0]['headers'], 'In sviluppo la chiave la mette il proxy');

        $client = $this->client('wtb_test_k');
        $this->sent = [];
        $client->get('/stock/', ['all' => 1]);
        self::assertSame('wtb_test_k', $this->sent[0]['headers']['X-API-Key']);
        self::assertSame('https://supplier.example/api/v1/stock/?all=1', $this->sent[0]['url']);
    }

    public function testEmptyKeyInProductionIsBlocking(): void
    {
        $client = $this->client('', true, 'live');
        $this->expectException(SupplierAuthException::class);
        $client->get('/stock/');
    }

    public function test429WaitsRetryAfterThenSucceeds(): void
    {
        $client = $this->client();
        $this->responses = [
            TransportResponse::json(429, ['detail' => 'throttled'], ['retry-after' => '7']),
            TransportResponse::json(200, ['ok' => true]),
        ];
        self::assertSame(['ok' => true], $client->get('/stock/'));
        self::assertSame([7.0], $this->sleeps);
    }

    public function test429GivesUpAfterThreeRetriesAndInteractiveDoesNotWaitLong(): void
    {
        $client = $this->client();
        $this->responses = array_fill(0, 5, TransportResponse::json(429, [], ['retry-after' => '2']));
        try {
            $client->get('/stock/');
            self::fail('Nessuna eccezione');
        } catch (SupplierRateLimitedException) {
            self::assertCount(3, $this->sleeps);
        }
        $this->sleeps = [];
        $this->responses = [TransportResponse::json(429, [])];
        $this->expectException(SupplierRateLimitedException::class);
        try {
            $client->post('/quote/', [], 'quote', true);
        } finally {
            self::assertSame([], $this->sleeps, 'Senza Retry-After si aspetterebbero 30 s: in una richiesta del cliente si rinuncia');
        }
    }

    public function testServerErrorsAndNetworkErrorsAreRetriedThenFail(): void
    {
        $client = $this->client();
        $this->responses = [new TransportException('timeout'), TransportResponse::json(502, []), TransportResponse::json(200, ['x' => 1])];
        self::assertSame(['x' => 1], $client->get('/products/'));
        self::assertCount(3, $this->sent);

        $this->sent = [];
        $this->responses = [new TransportException('a'), new TransportException('b'), new TransportException('c')];
        try {
            $client->post('/orders/', ['reference' => 'R-1'], 'order');
            self::fail('Nessuna eccezione');
        } catch (SupplierUnavailableException) {
            self::assertCount(3, $this->sent, 'Ordini: 3 tentativi con lo stesso corpo');
        }
    }

    public function testAuthErrorSetsTheDashboardFlagUntilACallSucceeds(): void
    {
        $client = $this->client();
        $this->responses = [TransportResponse::json(401, ['detail' => 'Chiave non valida.'])];
        try {
            $client->get('/stock/');
            self::fail('Nessuna eccezione');
        } catch (SupplierAuthException) {
            self::assertNotNull($this->flags->get(WtbClient::FLAG_AUTH_ERROR));
        }
        $client->get('/stock/');
        self::assertNull($this->flags->get(WtbClient::FLAG_AUTH_ERROR));
    }

    public function testRejectionsCarryFieldErrors(): void
    {
        $client = $this->client();
        $this->responses = [TransportResponse::json(400, ['stock' => ['ZX EU 42 (margin): only 1 available.']])];
        try {
            $client->post('/quote/', [], 'quote');
            self::fail('Nessuna eccezione');
        } catch (SupplierRejectedException $e) {
            self::assertTrue($e->hasStockIssues());
            self::assertSame(['ZX EU 42 (margin): only 1 available.'], $e->stockIssues());
        }
        $this->responses = [TransportResponse::json(400, ['ship_to' => 'Missing field: full_name'])];
        try {
            $client->post('/orders/', [], 'order');
            self::fail('Nessuna eccezione');
        } catch (SupplierRejectedException $e) {
            self::assertSame(['ship_to' => ['Missing field: full_name']], $e->fieldErrors);
        }
        $this->responses = [TransportResponse::json(400, ['pieces' => null, 'lines' => []])];
        try {
            $client->post('/quote/', [], 'quote');
            self::fail('Nessuna eccezione');
        } catch (SupplierRejectedException $e) {
            self::assertStringContainsString('senza dettagli', $e->getMessage());
        }
    }
}
