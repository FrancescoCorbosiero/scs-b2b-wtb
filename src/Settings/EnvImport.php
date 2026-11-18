<?php

declare(strict_types=1);

namespace App\Settings;

use App\Support\Config;

/**
 * Passaggio una tantum dal .env alle impostazioni (D-021). Le variabili qui sotto non si leggono più: se il .env
 * del server ne ha ancora una con un valore diverso dal default, il valore entra nell'impostazione (storico "dal
 * .env"), ma solo se quell'impostazione non è mai stata toccata dall'admin. Lanciato da bin/migrate.php a ogni
 * avvio del container: idempotente, e ricorda di togliere la riga dal .env.
 */
final class EnvImport
{
    public const BY = 'importato dal .env';

    /** Variabile del .env => impostazione che la sostituisce. */
    public const MAP = [
        'WTB_ORDERS_ENABLED' => 'supplier.orders_enabled',
        'WTB_ORDER_TRIGGER' => 'supplier.order_trigger',
        'WTB_PAYMENT_METHOD' => 'supplier.payment_method',
        'WTB_MAX_ORDER_EUR' => 'supplier.max_order',
        'WTB_SHIP_TO_EMAIL' => 'supplier.ship_to_email',
        'WTB_SHIP_TO_PHONE' => 'supplier.ship_to_phone',
        'WTB_CATALOG_ALL' => 'catalog.supplier_all',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly SettingsService $settings,
    ) {
    }

    /** @return list<string> una riga per ogni variabile ancora presente nel .env (importata, superflua o non valida) */
    public function run(): array
    {
        $out = [];
        foreach (self::MAP as $env => $key) {
            $raw = $this->config->string($env);
            if ($raw === '') {
                continue;
            }
            $where = 'Admin › Impostazioni (' . $key . ')';
            if ($this->settings->isCustomized($key) || $this->settings->audit($key, 1) !== []) {
                $out[] = sprintf('%s nel .env è ignorata: vale %s. Togli la riga dal .env.', $env, $where);
                continue;
            }
            $definition = $this->settings->registry()->get($key);
            try {
                $value = $definition->parseInput($raw);
            } catch (InvalidSetting $e) {
                $out[] = sprintf('%s=%s nel .env non è valida (%s): ignorata, vale il default. Togli la riga dal .env.', $env, $raw, $e->getMessage());
                continue;
            }
            if ($definition->encode($value) === $definition->encode($definition->default)) {
                $out[] = sprintf('%s nel .env non serve più (è il default, ora si cambia in %s): togli la riga.', $env, $where);
                continue;
            }
            $this->settings->setValue($key, $value, self::BY);
            $out[] = sprintf('%s=%s copiata in %s: da ora si cambia lì. Togli la riga dal .env.', $env, $raw, $where);
        }

        return $out;
    }
}
