<?php

declare(strict_types=1);

namespace Tests\Integration\Orders;

use App\Orders\AdminOrderService;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

/** Ordini di prova composti dall'admin (D-019) e lato cliente spento (PUBLIC_SITE=0). */
final class AdminNewOrderTest extends TestCase
{
    use OrderHelpers;

    /** @return array<string, string> */
    private function address(array $extra = []): array
    {
        return $extra + [
            'ship_name' => 'Mario Rossi',
            'ship_company' => 'Negozio Demo',
            'ship_street' => 'Via Roma 1',
            'ship_city' => 'Milano',
            'ship_zip' => '20100',
            'ship_province' => 'mi',
            'ship_country' => 'IT',
            'ship_phone' => '+39 333 1234567',
            'email' => 'prove@example.com',
            'admin_notes' => 'Prova del fornitore',
        ];
    }

    /** @return array{0: TestApp, 1: HttpClient} */
    private function admin(array $env = []): array
    {
        $app = new TestApp($env);
        $app->syncCatalog();
        $admin = $app->http('198.51.100.200');
        $admin->loginAdmin();

        return [$app, $admin];
    }

    public function testAdminComposesAnOrderAndCreatesTheSupplierOrder(): void
    {
        [$app, $admin] = $this->admin();
        $page = (string) $admin->get('/admin/ordini/nuovo?q=' . rawurlencode((string) $app->db->value('SELECT sku FROM products ORDER BY id LIMIT 1')))->getBody();
        self::assertStringContainsString('Aggiungi alla bozza', $page, 'Risultati della ricerca con le taglie');

        $key = $this->unitKey($app, 90011);
        $admin->post('/admin/ordini/nuovo/righe', ['qty' => [$key => '2']]);
        $admin->post('/admin/ordini/nuovo/righe', ['qty' => [$key => '1']]);
        self::assertStringContainsString('value="3"', (string) $admin->get('/admin/ordini/nuovo')->getBody(), 'Le paia si sommano');

        $before = count($app->mail->sent);
        $admin->post('/admin/ordini/nuovo', $this->address());
        $order = $app->db->one('SELECT * FROM orders ORDER BY id DESC LIMIT 1');
        self::assertNotNull($order);
        self::assertSame('admin', $order['source']);
        self::assertSame('pending', $order['status']);
        self::assertSame('fixture', $order['mode']);
        self::assertSame('MI', $order['ship_province']);
        self::assertSame('prove@example.com', $order['email']);
        self::assertSame(3, (int) $order['pairs']);
        self::assertNotNull($order['quote_snapshot'], 'Preventivo del fornitore salvato');
        self::assertSame($before, count($app->mail->sent), 'Nessuna email al salvataggio');
        self::assertSame(0, $app->db->int('SELECT is_active FROM users WHERE email = :e', ['e' => AdminOrderService::INTERNAL_EMAIL]), 'Utente tecnico inattivo');

        $show = (string) $admin->get('/admin/ordini/' . $order['id'])->getBody();
        self::assertStringContainsString('Ordine di prova (admin)', $show);
        self::assertStringNotContainsString('/conferma"', $show, 'Niente conferma del pagamento');
        self::assertStringContainsString('/admin/ordini/' . $order['id'] . '/fornitore"', $show, 'Bottone per l\'ordine al fornitore');

        $admin->post('/admin/ordini/' . $order['id'] . '/conferma');
        self::assertSame('pending', $app->db->value('SELECT status FROM orders WHERE id = :id', ['id' => $order['id']]), 'La conferma non si applica');

        $admin->post('/admin/ordini/' . $order['id'] . '/fornitore');
        $supplier = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = :o', ['o' => $order['id']]);
        self::assertNotNull($supplier);
        self::assertSame('simulated', $supplier['status']);
        $payload = json_decode((string) $supplier['request_payload'], true);
        self::assertSame('Via Roma 1', $payload['ship_to']['street']);
        self::assertSame(3, $payload['lines'][0]['quantity']);

        self::assertStringContainsString('Bozza vuota', (string) $admin->get('/admin/ordini/nuovo')->getBody(), 'Bozza svuotata dopo il salvataggio');
    }

    public function testInvalidAddressKeepsTheDraft(): void
    {
        [$app, $admin] = $this->admin();
        $admin->post('/admin/ordini/nuovo/righe', ['qty' => [$this->unitKey($app, 90011) => '1']]);
        $admin->post('/admin/ordini/nuovo', $this->address(['ship_zip' => '123', 'ship_phone' => '']));
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM orders'));
        self::assertStringNotContainsString('Bozza vuota', (string) $admin->get('/admin/ordini/nuovo')->getBody());
    }

    public function testEmptyDraftCreatesNothing(): void
    {
        [$app, $admin] = $this->admin();
        $admin->post('/admin/ordini/nuovo', $this->address());
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM orders'));
    }

    public function testNewOrderPageRequiresAdmin(): void
    {
        $app = new TestApp();
        $response = $app->http()->get('/admin/ordini/nuovo');
        self::assertSame('/admin/login', parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
    }

    public function testCustomerSideIsOffWithPublicSiteZero(): void
    {
        [$app, $admin] = $this->admin(['PUBLIC_SITE' => '0']);
        $guest = $app->http();
        $home = $guest->get('/');
        self::assertSame(302, $home->getStatusCode());
        self::assertSame('/admin', $home->getHeaderLine('Location'));
        foreach (['/vetrina', '/login', '/catalogo', '/carrello', '/account', '/richiedi-accesso', '/come-ordinare'] as $path) {
            self::assertSame(404, $guest->get($path)->getStatusCode(), $path);
        }
        self::assertSame(200, $guest->get('/healthz')->getStatusCode());

        $dashboard = (string) $admin->get('/admin')->getBody();
        self::assertStringContainsString('href="/admin/ordini/nuovo"', $dashboard);
        self::assertStringNotContainsString('href="/admin/clienti"', $dashboard, 'Voci del lato cliente nascoste');
        self::assertStringNotContainsString('href="/admin/margini"', $dashboard);
    }
}
