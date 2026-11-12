<?php

declare(strict_types=1);

namespace Tests\Integration\Site;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class AdminMarginsTest extends TestCase
{
    private function salePrice(TestApp $app, int $wtbVariant, string $type): int
    {
        return $app->db->int(
            'SELECT o.sale_price_cents FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.wtb_variant_id = :w AND o.stock_type = :t',
            ['w' => $wtbVariant, 't' => $type],
        );
    }

    public function testRulesRepriceImmediatelyWithPrecedence(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $admin = $app->http();
        $admin->loginAdmin();
        self::assertSame(7500, $this->salePrice($app, 90011, 'immediate'));

        $admin->post('/admin/margini', ['priority' => '10', 'match_type' => 'brand', 'match_value' => 'Nike', 'margin_type' => 'percent', 'margin_value' => '10']);
        // 71,37 + 10% = 78,507 → 79,00
        self::assertSame(7900, $this->salePrice($app, 90011, 'immediate'));
        self::assertSame(8100, $this->salePrice($app, 90013, 'extended'), '73,19 + 10% = 80,51 → 81,00');

        $admin->post('/admin/margini', ['priority' => '500', 'match_type' => 'sku', 'match_value' => 'ZX1001-100', 'stock_type' => 'immediate', 'margin_type' => 'fixed', 'margin_value' => '20,00']);
        // Le regole SKU vengono prima anche con priorità alta: 71,37 + 20 = 91,37 → 92,00 (solo pronta consegna)
        self::assertSame(9200, $this->salePrice($app, 90011, 'immediate'));
        self::assertSame(8100, $this->salePrice($app, 90011, 'extended'), 'Stock esteso: vale la regola brand (73,19 + 10% = 80,51 → 81,00)');

        $skuRule = $app->db->int("SELECT id FROM margin_rules WHERE match_type = 'sku'");
        $admin->post('/admin/margini/' . $skuRule . '/stato');
        self::assertSame(7900, $this->salePrice($app, 90011, 'immediate'), 'Regola disattivata: torna la regola brand');

        $admin->post('/admin/margini', ['priority' => '1', 'match_type' => 'line', 'match_value' => 'Peterson', 'margin_type' => 'fixed_price', 'margin_value' => '199,99']);
        self::assertSame(19999, $this->salePrice($app, 90081, 'extended'), 'Prezzo fisso esatto');
        $page = (string) $admin->get('/admin/margini')->getBody();
        self::assertStringContainsString('3 sotto costo', $page, 'Avviso prezzo fisso sotto costo');
        self::assertStringContainsString('Tocca 3 prodotti', $page);

        $brandRule = $app->db->int("SELECT id FROM margin_rules WHERE match_type = 'brand'");
        $admin->post('/admin/margini/' . $brandRule . '/elimina');
        self::assertSame(7500, $this->salePrice($app, 90011, 'immediate'), 'Senza regole: margine di default');

        $admin->post('/admin/margini', ['match_type' => 'gender', 'match_value' => 'aliens', 'margin_type' => 'percent', 'margin_value' => '5']);
        self::assertSame(2, $app->db->int('SELECT COUNT(*) FROM margin_rules'), 'Regola non valida rifiutata');
    }

    public function testProductsPageShowsCostsOnlyToAdminAndFeatured(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $admin = $app->http();
        $admin->loginAdmin();
        $id = $app->db->int("SELECT id FROM products WHERE sku = 'ZX1001-100'");
        $page = (string) $admin->get('/admin/prodotti/' . $id)->getBody();
        self::assertStringContainsString('71,37', $page, 'Il costo si vede in admin');
        $admin->post('/admin/prodotti/' . $id . '/evidenza');
        self::assertSame(1, $app->db->int('SELECT is_featured FROM products WHERE id = :id', ['id' => $id]));

        $app->createCustomer();
        $customer = $app->http('198.51.100.80');
        $customer->loginCustomer();
        $html = (string) $customer->get('/catalogo?evidenza=1')->getBody();
        self::assertStringContainsString('ZX1001-100', $html);
        self::assertStringContainsString('In evidenza', $html);
        self::assertStringNotContainsString('ZX1004-400', $html);
    }
}
