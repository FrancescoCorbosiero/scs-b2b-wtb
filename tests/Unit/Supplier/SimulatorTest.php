<?php

declare(strict_types=1);

namespace Tests\Unit\Supplier;

use App\Bootstrap;
use App\Supplier\Wtb\Fixture\FixtureTransport;
use PHPUnit\Framework\TestCase;

/** Il simulatore della modalità fixture rispetta le regole osservate in M0. */
final class SimulatorTest extends TestCase
{
    /** @param array<mixed> $payload @return array{0: int, 1: array<mixed>} */
    private function post(string $path, array $payload): array
    {
        $transport = new FixtureTransport(Bootstrap::root() . '/fixtures/wtb');
        $response = $transport->send('POST', 'https://x/api/v1' . $path, [], (string) json_encode($payload), 10);

        return [$response->status, json_decode($response->body, true)];
    }

    public function testImmediateFirstThenExtendedWithSeparateTransport(): void
    {
        // ZX1001-100 EU 36 (90011): 2 in pronta (stock) + 6 in esteso.
        [$status, $q] = $this->post('/quote/', ['lines' => [['variant_id' => 90011, 'vat_regime' => 'margin', 'quantity' => 4]], 'ship_to' => ['country' => 'IT']]);
        self::assertSame(200, $status);
        self::assertSame([[2, 'immediate'], [2, 'extended']], array_map(static fn (array $l): array => [$l['quantity'], $l['stock_type']], $q['lines']));
        self::assertSame('11.70', $q['shipping'], 'Zona: primo paio + 1 paio in più, solo pronta consegna');
        self::assertSame('11.70', $q['extended_stock_transport'], '2 paia estese');
        self::assertSame('0.00', $q['vat'], 'Solo margine: niente IVA');
    }

    public function testExtendedOnlyHasZeroShippingWithoutDecimals(): void
    {
        [, $q] = $this->post('/quote/', ['lines' => [['variant_id' => 90043, 'vat_regime' => 'margin', 'quantity' => 2]], 'ship_to' => ['country' => 'FR']]);
        self::assertSame('0', $q['shipping']);
        self::assertSame('11.70', $q['extended_stock_transport']);
    }

    public function testOrdinaryVatIncludesShipping(): void
    {
        [, $q] = $this->post('/quote/', ['lines' => [['variant_id' => 90054, 'vat_regime' => 'ordinary', 'quantity' => 1]], 'ship_to' => ['country' => 'DE']]);
        // 22% di (79.67 + 16.20) = 21.0914 → 21.09
        self::assertSame('21.09', $q['vat']);
        self::assertSame('IVA ordinaria', $q['vat_note']);
    }

    public function testErrorsHaveTheSupplierShape(): void
    {
        [$status, $body] = $this->post('/quote/', ['lines' => [['variant_id' => 90011, 'vat_regime' => 'margin', 'quantity' => 99]]]);
        self::assertSame(400, $status);
        self::assertSame(['ZX1001-100 EU 36 (margin): only 8 available.'], $body['stock']);

        [$status, $body] = $this->post('/quote/', ['lines' => [['variant_id' => 90011, 'vat_regime' => 'margin', 'quantity' => 1]], 'ship_to' => ['country' => 'US']]);
        self::assertSame(400, $status);
        self::assertStringStartsWith('Shipping to US is not available', $body['ship_to']);

        [$status, $body] = $this->post('/quote/', ['lines' => [['variant_id' => 1, 'quantity' => 1]]]);
        self::assertSame(400, $status);
        self::assertNull($body['total']);

        [$status, $body] = $this->post('/orders/', ['lines' => [['variant_id' => 90011, 'quantity' => 1]], 'ship_to' => ['country' => 'IT']]);
        self::assertSame(400, $status);
        self::assertArrayHasKey('reference', $body);
    }

    public function testOrderUsesBankTransferInstructions(): void
    {
        [$status, $order] = $this->post('/orders/', [
            'reference' => 'SCSW-5-1',
            'lines' => [['variant_id' => 90011, 'vat_regime' => 'margin', 'quantity' => 1]],
            'ship_to' => ['full_name' => 'A', 'street' => 'B', 'city' => 'C', 'zip_code' => '00100', 'country' => 'IT'],
            'payment_method' => 'bank_transfer',
        ]);
        self::assertSame(201, $status);
        self::assertSame('SCSW-5-1', $order['reference']);
        self::assertSame($order['total'], $order['payment_instructions']['amount']);
        self::assertFalse($order['is_paid']);
    }
}
