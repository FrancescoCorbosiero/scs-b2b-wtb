<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\ClientIpMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\CustomerContextMiddleware;
use App\Http\Middleware\LocaleMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Support\Config;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Costruisce l'applicazione Slim: middleware globali (ordine documentato qui sotto) e rotte (config/routes.php).
 */
final class AppFactory
{
    /** @return App<ContainerInterface> */
    public static function create(ContainerInterface $container): App
    {
        $app = SlimAppFactory::createFromContainer($container);
        /** @var Config $config */
        $config = $container->get(Config::class);

        (require $config->root() . '/config/routes.php')($app);

        // Esecuzione (dall'esterno all'interno): errori → routing → header di sicurezza → IP reale →
        // sessione → cliente/admin → lingua → parsing del corpo → CSRF → middleware di gruppo → controller.
        $app->add(CsrfMiddleware::class);
        $app->addBodyParsingMiddleware();
        $app->add(LocaleMiddleware::class);
        $app->add(CustomerContextMiddleware::class);
        $app->add(SessionMiddleware::class);
        $app->add(ClientIpMiddleware::class);
        $app->add(SecurityHeadersMiddleware::class);
        $app->addRoutingMiddleware();

        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);
        $errors = $app->addErrorMiddleware($config->debug(), true, true, $logger);
        $errors->setDefaultErrorHandler($container->get(ErrorHandler::class));

        return $app;
    }
}
