<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use App\Shipping\CountryChoices;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/** Paesi "smart" nei menu (D-020): più usati in testa, ultimo usato, mai dati di altri clienti. */
final class CountryChoicesTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = new TestApp();
    }

    /** Riga di ordine minima: i campi obbligatori senza default ricevono un valore qualsiasi. */
    private function order(int $userId, string $country, string $source = 'checkout'): void
    {
        $row = [];
        foreach ($this->app->db->all('PRAGMA table_info(orders)') as $col) {
            if ((int) $col['notnull'] === 1 && $col['dflt_value'] === null && (int) $col['pk'] === 0) {
                $row[(string) $col['name']] = str_contains(strtoupper((string) $col['type']), 'INT') ? 0 : 'x';
            }
        }
        $row = array_merge($row, [
            'user_id' => $userId, 'ship_country' => $country, 'billing_country' => 'IT', 'mode' => 'fixture',
            'status' => 'pending', 'source' => $source, 'vat_scheme' => 'domestic', 'checkout_token' => bin2hex(random_bytes(8)),
            'created_at' => '2026-11-01T10:00:00Z', 'updated_at' => '2026-11-01T10:00:00Z',
        ]);
        $columns = array_column($this->app->db->all('PRAGMA table_info(orders)'), 'name');
        $this->app->db->insert('orders', array_intersect_key($row, array_flip($columns)));
    }

    public function testCustomerSeesOnlyOwnCountriesFirstThenAlphabetical(): void
    {
        $alice = $this->app->createCustomer(['email' => 'alice@example.com']);
        $bob = $this->app->createCustomer(['email' => 'bob@example.com']);
        $this->order($alice, 'DE');
        $this->order($alice, 'DE');
        $this->order($alice, 'FR');
        $this->order($bob, 'ES');

        $choices = $this->app->get(CountryChoices::class);
        $groups = $choices->groups('it', $alice, ['AT']);

        self::assertSame(['DE', 'FR', 'AT', 'IT'], array_keys($groups['top']));
        self::assertArrayNotHasKey('ES', $groups['top'], 'Mai i paesi degli ordini di altri clienti');
        self::assertArrayHasKey('ES', $groups['rest']);
        self::assertSame([], array_intersect_key($groups['top'], $groups['rest']), 'Nessun paese due volte');

        self::assertSame('FR', $choices->lastUsed($alice));
        self::assertSame('ES', $choices->lastUsed($bob));
    }

    public function testAdminSeesAllOrdersAndNewCustomerOnlyHomeAndItaly(): void
    {
        $alice = $this->app->createCustomer(['email' => 'alice@example.com']);
        $this->order($alice, 'ES');
        $this->order($alice, 'ES');
        $this->order($alice, 'FR', 'admin');

        $choices = $this->app->get(CountryChoices::class);
        self::assertSame(['ES', 'FR', 'IT'], array_keys($choices->groups('it', null)['top']));
        self::assertSame('FR', $choices->lastUsed(null), "Per l'admin: l'ultimo ordine di prova");

        $carol = $this->app->createCustomer(['email' => 'carol@example.com']);
        self::assertSame(['AT', 'IT'], array_keys($choices->groups('it', $carol, ['AT'])['top']));
        self::assertNull($choices->lastUsed($carol));
    }
}
