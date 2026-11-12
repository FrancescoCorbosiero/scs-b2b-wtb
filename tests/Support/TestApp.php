<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Bootstrap;
use App\Http\AppFactory;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\FrozenClock;
use App\Support\Mail\ArrayTransport;
use App\Support\Mail\MailTransport;
use App\Support\Migrator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\App;

/**
 * Applicazione completa per i test: SQLite in memoria con le migrazioni vere, orologio fermo,
 * email in memoria, fornitore in modalità fixture.
 */
final class TestApp
{
    public const ADMIN_PASSWORD = 'admin-test-password';

    public readonly ContainerInterface $container;
    public readonly Db $db;
    public readonly FrozenClock $clock;
    public readonly ArrayTransport $mail;

    /** @var App<ContainerInterface>|null */
    private ?App $app = null;

    /** @param array<string, string|int|bool> $env @param array<string, mixed> $overrides */
    public function __construct(array $env = [], array $overrides = [])
    {
        $config = Config::fromArray(Bootstrap::root(), $env + self::defaultEnv());
        $this->db = Db::open(':memory:');
        $this->clock = new FrozenClock('2026-11-12T09:00:00Z');
        $this->mail = new ArrayTransport();
        $this->container = Bootstrap::container($config, $overrides + [
            Db::class => $this->db,
            Clock::class => $this->clock,
            MailTransport::class => $this->mail,
            LoggerInterface::class => new NullLogger(),
        ]);
        (new Migrator($this->db, Bootstrap::root() . '/database/migrations', $this->clock))->migrate();
    }

    /** @return array<string, string> */
    public static function defaultEnv(): array
    {
        return [
            'APP_ENV' => 'test',
            'APP_URL' => 'https://b2b.example.test',
            'DB_PATH' => ':memory:',
            'MAIL_TRANSPORT' => 'array',
            'WTB_MODE' => 'fixture',
            'WTB_WEBHOOK_SECRET' => 'whsec_test_secret_for_tests',
            'ADMIN_PASSWORD_HASH' => self::adminHash(),
            'BANK_HOLDER' => 'Demo Holder',
            'BANK_IBAN' => 'IT60X0542811101000000123456',
            'BANK_BIC' => 'DEMOITMM',
            'BANK_NAME' => 'Banca Demo',
            'TRUSTED_PROXIES' => '10.0.0.0/8',
        ];
    }

    public static function adminHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(self::ADMIN_PASSWORD, PASSWORD_ARGON2ID);
    }

    /** @return App<ContainerInterface> */
    public function app(): App
    {
        return $this->app ??= AppFactory::create($this->container);
    }

    public function http(string $ip = '203.0.113.10'): HttpClient
    {
        return new HttpClient($this, $ip);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object
    {
        /** @var T */
        return $this->container->get($class);
    }

    /** Crea un cliente attivo con password (per i test). @param array<string, mixed> $data */
    public function createCustomer(array $data = [], string $password = 'customer-password'): int
    {
        $repo = $this->get(\App\Accounts\UserRepository::class);
        $id = $repo->create($data + [
            'email' => 'cliente@example.com',
            'name' => 'Mario Rossi',
            'company' => 'Rossi Sneakers S.r.l.',
            'vat_number' => 'IT01234567897',
            'phone' => '+39 333 1234567',
            'address_street' => 'Via Roma 1',
            'address_city' => 'Milano',
            'address_zip' => '20100',
            'address_province' => 'MI',
            'country_code' => 'IT',
            'locale' => 'it',
        ]);
        if ($password !== '') {
            $repo->setPassword($id, $password);
        }

        return $id;
    }
}
