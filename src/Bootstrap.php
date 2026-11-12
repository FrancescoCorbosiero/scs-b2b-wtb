<?php

declare(strict_types=1);

namespace App;

use App\Support\Config;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/** Avvio comune a web, CLI e test: configurazione dall'ambiente e contenitore delle dipendenze. */
final class Bootstrap
{
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @param array<string, mixed> $overrides definizioni che sostituiscono quelle di config/container.php (test)
     */
    public static function container(?Config $config = null, array $overrides = []): ContainerInterface
    {
        $config ??= Config::load(self::root());
        date_default_timezone_set('UTC');
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions((require self::root() . '/config/container.php')($config));
        if ($overrides !== []) {
            $builder->addDefinitions($overrides);
        }

        return $builder->build();
    }
}
