<?php

declare(strict_types=1);

use App\Accounts\AdminAuth;
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
        'settings.listeners' => [],
    ];
};
