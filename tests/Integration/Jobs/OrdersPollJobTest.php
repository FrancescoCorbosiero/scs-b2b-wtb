<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\OrdersPollJob;
use App\Orders\SupplierOrderUpdater;
use App\Supplier\Model\SupplierOrderData;
use App\Supplier\Model\SupplierOrderRequest;
use App\Supplier\Model\SupplierOrderResult;
use App\Supplier\Model\SupplierQuote;
use App\Supplier\SupplierOrders;
use App\Support\Deferred;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

/** Rete di sicurezza ai webhook (docs/06): solo live, ordini non conclusi e fermi da più di 2 ore, stesse transizioni. */
final class OrdersPollJobTest extends TestCase
{
    use OrderHelpers;

    public function testDoesNothingOutsideLiveMode(): void
    {
        $app = new TestApp();
        $outcome = $app->get(OrdersPollJob::class)->run();
        self::assertSame('ok', $outcome->status);
        self::assertStringContainsString('solo live', $outcome->message);
        self::assertSame('orders_poll', $app->get(OrdersPollJob::class)->name());
    }

    public function testRereadsStaleLiveOrdersThroughTheSameTransitions(): void
    {
        $app = new TestApp();
        $this->placeOrder($app);
        $app->db->run("UPDATE orders SET status = 'confirmed', mode = 'live' WHERE id = 1001");
        $base = ['order_id' => 1001, 'mode' => 'live', 'payment_method' => 'bank_transfer', 'total_cents' => 30000, 'created_at' => '2026-11-12T05:00:00Z'];
        $app->db->insert('supplier_orders', $base + ['reference' => 'SCSW-1001-1', 'status' => 'paid', 'is_paid' => 1, 'updated_at' => '2026-11-12T05:00:00Z']);
        $app->db->insert('supplier_orders', $base + ['reference' => 'SCSW-1001-9', 'status' => 'received', 'updated_at' => '2026-11-12T08:30:00Z']);
        $app->db->insert('supplier_orders', $base + ['reference' => 'SCSW-1001-7', 'status' => 'pending', 'updated_at' => '2026-11-12T05:00:00Z']);

        $supplier = new class implements SupplierOrders {
            /** @var list<string> */
            public array $asked = [];

            public function quote(SupplierOrderRequest $request, bool $interactive = false): SupplierQuote
            {
                throw new \LogicException('non usato');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierOrderResult
            {
                throw new \LogicException('non usato');
            }

            public function findByReference(string $reference): ?SupplierOrderData
            {
                $this->asked[] = $reference;
                if ($reference !== 'SCSW-1001-1') {
                    return null;
                }
                $tracking = [['carrier' => 'BRT', 'number' => 'DEMO000123456789', 'url' => 'https://www.brt.it/it/tracking?parcelID=DEMO000123456789']];

                return new SupplierOrderData(9042, 'WTB/2026/11/9042', $reference, 'shipped', true, 'bank_transfer', 25000, 2000, 3000, 30000, [], $tracking, null, false, '', ['reference' => $reference]);
            }

            public function getOrder(int $id): SupplierOrderData
            {
                throw new \LogicException('non usato');
            }

            public function listOrders(int $page = 1): array
            {
                return ['orders' => [], 'has_more' => false];
            }

            public function mode(): string
            {
                return 'live';
            }
        };
        $job = new OrdersPollJob($supplier, $app->get(SupplierOrderUpdater::class), $app->db, $app->clock, $app->get(Deferred::class), 3600);
        $before = count($app->mail->sentTo('cliente@example.com'));
        $outcome = $job->run();

        self::assertSame(['SCSW-1001-1', 'SCSW-1001-7'], $supplier->asked, 'Solo gli ordini fermi da più di 2 ore');
        self::assertSame(['checked' => 2, 'applied' => 1, 'stale' => 0, 'not_found' => 1, 'errors' => 0], $outcome->counts);
        self::assertSame('shipped', $app->db->value("SELECT status FROM supplier_orders WHERE reference = 'SCSW-1001-1'"));
        self::assertSame('shipped', $app->db->value('SELECT status FROM orders WHERE id = 1001'));
        self::assertCount($before + 1, $app->mail->sentTo('cliente@example.com'), 'Email di spedizione partita dal job');
        self::assertSame('error', $app->db->value("SELECT status FROM supplier_orders WHERE reference = 'SCSW-1001-7'"), 'Creazione mai confermata: si può ritentare');

        $app->clock->advance(3 * 3600);
        $supplier->asked = [];
        $job->run();
        self::assertSame(['SCSW-1001-9', 'SCSW-1001-7'], $supplier->asked, 'Gli spediti non si rileggono più; gli errori recenti sì');
        self::assertCount($before + 1, $app->mail->sentTo('cliente@example.com'));
    }
}
