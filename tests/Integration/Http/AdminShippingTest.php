<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/** /admin/spedizione (docs/05): zone, tariffe, soglie, tempi, paesi, confronto col costo del fornitore. */
final class AdminShippingTest extends TestCase
{
    public function testZonesAreEditableAndReachTheCustomerPages(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $admin = $app->http('198.51.100.200');
        $admin->loginAdmin();
        $page = $admin->get('/admin/spedizione');
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        self::assertStringContainsString('Costo fornitore per 5 paia', $html, 'Confronto con la fotografia delle tariffe');
        self::assertStringContainsString('Tariffe del fornitore (solo admin)', $html);

        $admin->post('/admin/spedizione/IT', [
            'name_it' => 'Italia', 'name_en' => 'Italy', 'fee' => '20', 'free_from_items' => '10',
            'transit_days_min' => '1', 'transit_days_max' => '3', 'sort_order' => '10',
        ]);
        $zone = $app->db->one("SELECT * FROM shipping_zones WHERE zone = 'IT'");
        self::assertSame([2000, 10, 1, 3], [$zone['fee_cents'], $zone['free_from_items'], $zone['transit_days_min'], $zone['transit_days_max']]);
        $public = (string) $app->http('198.51.100.9')->get('/spedizioni')->getBody();
        self::assertStringContainsString('20,00', $public, 'La pagina pubblica legge le tariffe vere');

        foreach ([['fee' => 'venti'], ['free_from_items' => '0'], ['transit_days_min' => '5', 'transit_days_max' => '2'], ['name_it' => '']] as $bad) {
            $admin->post('/admin/spedizione/IT', $bad + [
                'name_it' => 'Italia', 'name_en' => 'Italy', 'fee' => '25', 'free_from_items' => '',
                'transit_days_min' => '1', 'transit_days_max' => '3', 'sort_order' => '10',
            ]);
            self::assertSame(2000, $app->db->int("SELECT fee_cents FROM shipping_zones WHERE zone = 'IT'"), 'Valori non validi: niente cambia');
        }
        self::assertSame(303, $app->http('198.51.100.10')->get('/admin/spedizione')->getStatusCode(), 'Solo admin');
    }

    public function testCountriesZonesAndZoneLifecycle(): void
    {
        $app = new TestApp();
        $admin = $app->http('198.51.100.200');
        $admin->loginAdmin();
        $admin->post('/admin/spedizione', [
            'zone' => 'UE_SUD', 'name_it' => 'UE sud', 'name_en' => 'EU south', 'fee' => '35,50', 'free_from_items' => '',
            'transit_days_min' => '3', 'transit_days_max' => '5', 'sort_order' => '25',
        ]);
        self::assertSame(3550, $app->db->int("SELECT fee_cents FROM shipping_zones WHERE zone = 'UE_SUD'"));

        $admin->post('/admin/spedizione/paesi', ['zone' => ['MT' => 'UE_SUD', 'CY' => 'UE_SUD', 'XX' => 'IT'], 'served' => ['IT' => '1', 'MT' => '1']]);
        self::assertSame('UE_SUD', $app->db->value("SELECT zone FROM countries WHERE code = 'MT'"));
        self::assertSame(1, $app->db->int("SELECT served FROM countries WHERE code = 'MT'"));
        self::assertSame(0, $app->db->int("SELECT served FROM countries WHERE code = 'CY'"), 'Non spuntato: non servito');
        self::assertSame(0, $app->db->int("SELECT served FROM countries WHERE code = 'DE'"));
        self::assertSame(0, $app->db->int("SELECT COUNT(*) FROM countries WHERE code = 'XX'"));

        $admin->post('/admin/spedizione/UE_SUD/elimina');
        self::assertSame(1, $app->db->int("SELECT COUNT(*) FROM shipping_zones WHERE zone = 'UE_SUD'"), 'Con paesi non si elimina');
        $admin->post('/admin/spedizione/paesi', ['zone' => ['MT' => 'EU', 'CY' => 'EU'], 'served' => []]);
        self::assertSame(1, $app->db->int("SELECT served FROM countries WHERE code = 'IT'"), 'L\'Italia resta servita');
        $admin->post('/admin/spedizione/UE_SUD/elimina');
        self::assertSame(0, $app->db->int("SELECT COUNT(*) FROM shipping_zones WHERE zone = 'UE_SUD'"));
    }
}
