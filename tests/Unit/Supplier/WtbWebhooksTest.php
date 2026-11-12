<?php

declare(strict_types=1);

namespace Tests\Unit\Supplier;

use App\Orders\PaymentInstructions;
use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\Wtb\WebhookVerifier;
use App\Supplier\Wtb\WtbConfig;
use App\Supplier\Wtb\WtbMapper;
use App\Supplier\Wtb\WtbWebhooks;
use App\Support\Config;
use App\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class WtbWebhooksTest extends TestCase
{
    private function webhooks(string $mode = 'fixture', string $secret = 'whsec_unit'): WtbWebhooks
    {
        $clock = new FrozenClock('2026-11-12T09:00:00Z');
        $config = WtbConfig::fromConfig(Config::fromArray(dirname(__DIR__, 3), [
            'APP_ENV' => $mode === 'live' ? 'production' : 'test', 'WTB_MODE' => $mode, 'WTB_WEBHOOK_SECRET' => $secret,
        ]));

        return new WtbWebhooks(new WebhookVerifier($secret, $clock), new WtbMapper(), $config, $clock);
    }

    public function testParsesFixtureEventsWithDeliveryFallback(): void
    {
        $webhooks = $this->webhooks();
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/wtb/webhooks/order.shipped.json');
        $event = $webhooks->parse(['x-wtb-delivery' => 'abc-1'], $raw);
        self::assertSame('abc-1', $event->deliveryId);
        self::assertSame('order.shipped', $event->event);
        self::assertNotNull($event->order);
        self::assertSame('SCSW-1-1', $event->order->reference);
        self::assertSame('shipped', $event->order->status);
        self::assertSame('DEMO000123456789', $event->order->tracking[0]['number']);

        $ping = $webhooks->parse([], (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/wtb/webhooks/ping.json'));
        self::assertTrue($ping->isPing());
        self::assertSame('evt-7000', $ping->deliveryId, 'Senza intestazione: id dell\'evento');
        self::assertNull($ping->order);
    }

    public function testRejectsUnreadableBodies(): void
    {
        $webhooks = $this->webhooks();
        foreach (['not json', '{"event":"order.paid"}', '{"event":"bad event!","id":1}', '{"event":"ping"}'] as $body) {
            try {
                $webhooks->parse([], $body);
                self::fail('Atteso un errore per ' . $body);
            } catch (SupplierFormatException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSimulatedEventsAreSignedAndVerifiable(): void
    {
        $webhooks = $this->webhooks();
        $sim = $webhooks->simulate('order.paid', $webhooks->blankOrder('SCSW-5-1'));
        self::assertTrue($webhooks->verify($sim['headers'], $sim['body']));
        self::assertStringStartsWith('sim-', $sim['headers']['x-wtb-delivery']);
        $event = $webhooks->parse($sim['headers'], $sim['body']);
        self::assertSame('paid', $event->order?->status);
        self::assertTrue($event->order?->isPaid);
        self::assertFalse($webhooks->verify($sim['headers'], $sim['body'] . ' '), 'Corpo cambiato: firma non valida');

        $shipped = $webhooks->parse([], $webhooks->simulate('order.shipped', null)['body']);
        self::assertCount(1, $shipped->order?->tracking ?? []);

        $this->expectException(\LogicException::class);
        $this->webhooks('live')->simulate('ping', null);
    }

    public function testPaymentInstructionsKeepEveryKeyAndHighlightTheUsefulOnes(): void
    {
        $rows = PaymentInstructions::fromJson('{"iban":"IT60X0542811101000000123456","beneficiary":"Demo","reason":"WTB/1","amount":"10.00","extra":{"bank":"Banca Demo"},"note":null}');
        self::assertSame(['iban', 'beneficiary', 'reason', 'amount', 'bank', 'other'], array_column($rows, 'kind'));
        self::assertSame([true, false, true, true, false, false], array_column($rows, 'highlight'));
        self::assertSame('extra.bank', $rows[4]['key']);
        self::assertSame([], PaymentInstructions::fromJson(null));
        self::assertSame([], PaymentInstructions::fromJson('null'));
    }
}
