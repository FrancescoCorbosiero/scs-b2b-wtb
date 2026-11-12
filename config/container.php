<?php

declare(strict_types=1);

use App\Accounts\AdminAuth;
use App\Catalog\ImageService;
use App\Catalog\ProductAggregates;
use App\Jobs\BackupJob;
use App\Jobs\CleanupJob;
use App\Jobs\ImagePrefetchJob;
use App\Jobs\JobRunner;
use App\Jobs\ProductsSyncJob;
use App\Jobs\Scheduler;
use App\Jobs\StockSyncJob;
use App\Pricing\RepriceService;
use App\Supplier\SupplierCatalog;
use App\Supplier\SupplierOrders;
use App\Supplier\Wtb\Fixture\FixtureTransport;
use App\Supplier\Wtb\Http\CurlTransport;
use App\Supplier\Wtb\Http\Transport;
use App\Supplier\Wtb\WebhookVerifier;
use App\Supplier\Wtb\WtbCatalog;
use App\Supplier\Wtb\WtbClient;
use App\Supplier\Wtb\WtbConfig;
use App\Supplier\Wtb\WtbOrders;
use App\Accounts\LoginThrottle;
use App\Http\TwigExtension;
use App\Http\ViewContext;
use App\Settings\Registry;
use App\Settings\SettingsService;
use App\Support\ClientIp;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\LoggerFactory;
use App\Support\Mail\ArrayTransport;
use App\Support\Mail\LogTransport;
use App\Support\Mail\MailTransport;
use App\Support\Mail\MessageBuilder;
use App\Support\Mail\SmtpTransport;
use App\Support\Paths;
use App\Support\SessionStore;
use App\Support\Translator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use Twig\Environment;

use function DI\factory;

/**
 * Definizioni del contenitore (PHP-DI). Tutto ciò che non è qui viene costruito per autowiring.
 * I test sostituiscono alcune voci (database in memoria, orologio fermo, email in memoria).
 */
return static function (Config $config): array {
    return [
        Config::class => $config,
        Clock::class => factory(static fn (): Clock => new Clock()),
        Paths::class => factory(static fn (): Paths => new Paths($config->root())),
        Db::class => factory(static fn (): Db => Db::open($config->dbPath())),
        LoggerInterface::class => factory(static fn (): LoggerInterface => LoggerFactory::create($config)),
        Translator::class => factory(static fn (): Translator => new Translator($config->root() . '/lang')),
        ViewContext::class => factory(static fn (): ViewContext => new ViewContext()),
        ResponseFactoryInterface::class => factory(static fn (): ResponseFactoryInterface => new ResponseFactory()),
        Registry::class => factory(static fn (): Registry => new Registry()),
        SettingsService::class => factory(static function (ContainerInterface $c): SettingsService {
            $settings = new SettingsService($c->get(Db::class), $c->get(Registry::class), $c->get(Clock::class));
            foreach ((array) ($c->has('settings.listeners') ? $c->get('settings.listeners') : []) as $listener) {
                $settings->onChange($listener);
            }

            return $settings;
        }),
        SessionStore::class => factory(static fn (ContainerInterface $c): SessionStore => new SessionStore(
            $c->get(Db::class),
            $c->get(Clock::class),
            max(600, $config->int('SESSION_LIFETIME', 28800)),
        )),
        ClientIp::class => factory(static fn (): ClientIp => new ClientIp($config->list('TRUSTED_PROXIES'))),
        AdminAuth::class => factory(static fn (ContainerInterface $c): AdminAuth => new AdminAuth(
            $config->string('ADMIN_PASSWORD_HASH'),
            $c->get(LoginThrottle::class),
        )),
        Twig::class => factory(static function (ContainerInterface $c) use ($config): Twig {
            $twig = Twig::create($config->root() . '/templates', [
                'cache' => $config->isProduction() ? $config->varPath('cache/twig') : false,
                'autoescape' => 'html',
                'strict_variables' => !$config->isProduction(),
            ]);
            $twig->addExtension(new TwigExtension(
                $c->get(Translator::class),
                $c->get(ViewContext::class),
                $c->get(SettingsService::class),
                $config,
                $config->root() . '/public',
                $c->get(\App\Shipping\CountryRepository::class),
                $c->get(\App\Http\AdminMenu::class),
            ));

            return $twig;
        }),
        Environment::class => factory(static fn (ContainerInterface $c): Environment => $c->get(Twig::class)->getEnvironment()),
        MessageBuilder::class => factory(static fn (): MessageBuilder => new MessageBuilder(
            $config->string('MAIL_FROM', 'no-reply@example.com'),
            $config->string('MAIL_FROM_NAME', 'SCS B2B'),
        )),
        MailTransport::class => factory(static fn (ContainerInterface $c): MailTransport => match ($config->string('MAIL_TRANSPORT', 'log')) {
            'smtp' => new SmtpTransport(
                $c->get(MessageBuilder::class),
                $config->string('SMTP_HOST'),
                $config->int('SMTP_PORT', 587),
                $config->string('SMTP_USER'),
                $config->string('SMTP_PASSWORD'),
                $config->string('SMTP_ENCRYPTION', 'tls'),
            ),
            'array' => new ArrayTransport(),
            default => new LogTransport($c->get(MessageBuilder::class), $config->varPath('mail')),
        }),
        // Impostazioni che cambiano i prezzi o ciò che è vendibile: ricalcolo immediato (risolto in modo pigro).
        'settings.listeners' => factory(static fn (ContainerInterface $c): array => [
            static function (string $key) use ($c): void {
                if (str_starts_with($key, 'prices.')) {
                    $c->get(RepriceService::class)->repriceAll();
                } elseif (in_array($key, ['catalog.regimes', 'catalog.sell_extended'], true)) {
                    $c->get(ProductAggregates::class)->recompute();
                }
            },
        ]),

        // --- Fornitore (solo src/Supplier/Wtb conosce WTB) ---------------------------------------
        WtbConfig::class => factory(static fn (): WtbConfig => WtbConfig::fromConfig($config)),
        Transport::class => factory(static fn (ContainerInterface $c): Transport => $c->get(WtbConfig::class)->mode === 'fixture'
            ? $c->get(FixtureTransport::class)
            : new CurlTransport()),
        FixtureTransport::class => factory(static fn (ContainerInterface $c): FixtureTransport => new FixtureTransport($c->get(WtbConfig::class)->fixturesDir)),
        WtbClient::class => factory(static fn (ContainerInterface $c): WtbClient => new WtbClient(
            $c->get(WtbConfig::class),
            $c->get(Transport::class),
            $c->get(LoggerInterface::class),
            $c->get(\App\Support\SystemFlags::class),
            $c->has('wtb.sleep') ? $c->get('wtb.sleep') : null,
        )),
        SupplierCatalog::class => \DI\get(WtbCatalog::class),
        SupplierOrders::class => \DI\get(WtbOrders::class),
        \App\Supplier\SupplierWebhooks::class => \DI\get(\App\Supplier\Wtb\WtbWebhooks::class),
        \App\Supplier\ShippingSnapshot::class => \DI\get(\App\Supplier\Wtb\WtbShippingSnapshot::class),
        WebhookVerifier::class => factory(static fn (ContainerInterface $c): WebhookVerifier => new WebhookVerifier(
            $c->get(WtbConfig::class)->webhookSecret,
            $c->get(Clock::class),
        )),
        ImageService::class => factory(static fn (ContainerInterface $c): ImageService => new ImageService(
            $c->get(Db::class),
            $c->get(WtbConfig::class),
            $c->get(Clock::class),
            $c->get(LoggerInterface::class),
            $config->varPath('images'),
        )),

        // --- Job periodici ------------------------------------------------------------------------
        JobRunner::class => factory(static fn (ContainerInterface $c): JobRunner => new JobRunner(
            $c->get(Db::class),
            $c->get(Clock::class),
            $c->get(LoggerInterface::class),
            $config->varPath('locks'),
        )),
        ProductsSyncJob::class => factory(static fn (ContainerInterface $c): ProductsSyncJob => new ProductsSyncJob(
            $c->get(\App\Catalog\ProductSync::class),
            max(600, $config->int('WTB_PRODUCTS_SYNC_INTERVAL', 10800)),
        )),
        StockSyncJob::class => factory(static fn (ContainerInterface $c): StockSyncJob => new StockSyncJob(
            $c->get(\App\Catalog\StockSync::class),
            max(300, $config->int('WTB_STOCK_SYNC_INTERVAL', 900)),
        )),
        BackupJob::class => factory(static fn (ContainerInterface $c): BackupJob => new BackupJob(
            $c->get(Db::class),
            $c->get(Clock::class),
            $config->varPath('backups'),
            max(1, $config->int('BACKUP_KEEP_DAYS', 14)),
        )),
        \App\Orders\ReceiptNumberer::class => factory(static fn (ContainerInterface $c): \App\Orders\ReceiptNumberer => new \App\Orders\ReceiptNumberer(
            $c->get(Db::class),
            $config->string('RECEIPT_PREFIX', 'PFW'),
        )),
        \App\Jobs\OrdersPollJob::class => factory(static fn (ContainerInterface $c): \App\Jobs\OrdersPollJob => new \App\Jobs\OrdersPollJob(
            $c->get(SupplierOrders::class),
            $c->get(\App\Orders\SupplierOrderUpdater::class),
            $c->get(Db::class),
            $c->get(Clock::class),
            $c->get(\App\Support\Deferred::class),
            max(600, $config->int('WTB_ORDERS_POLL_INTERVAL', 3600)),
        )),
        // Pulizie aggiunte dai moduli: eventi webhook oltre i 90 giorni.
        'cleanup.extra' => factory(static fn (ContainerInterface $c): array => [
            static fn (): int => $c->get(Db::class)->run('DELETE FROM webhook_events WHERE received_at < :t', ['t' => $c->get(Clock::class)->isoAgo(90 * 86400)])->rowCount(),
        ]),
        CleanupJob::class => \DI\autowire()->constructorParameter('extra', \DI\get('cleanup.extra')),
        'jobs' => factory(static fn (ContainerInterface $c): array => [
            $c->get(StockSyncJob::class),
            $c->get(ProductsSyncJob::class),
            $c->get(\App\Jobs\OrdersPollJob::class),
            $c->get(ImagePrefetchJob::class),
            $c->get(CleanupJob::class),
            $c->get(BackupJob::class),
        ]),
        Scheduler::class => factory(static fn (ContainerInterface $c): Scheduler => new Scheduler(
            $c->get('jobs'),
            $c->get(JobRunner::class),
            $c->get(Db::class),
            $c->get(Clock::class),
        )),
    ];
};
