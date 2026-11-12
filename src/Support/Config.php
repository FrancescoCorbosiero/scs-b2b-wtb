<?php

declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;

/**
 * Configurazione letta dall'ambiente (.env): segreti e interruttori.
 * Le manopole del titolare stanno invece nel registro delle impostazioni (src/Settings).
 */
final class Config
{
    /** @param array<string, string> $values */
    private function __construct(
        private readonly string $root,
        private readonly array $values,
    ) {
    }

    public static function load(string $root): self
    {
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }
        $values = [];
        foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }
        $config = new self($root, $values);
        $config->validate();

        return $config;
    }

    /** @param array<string, string|int|bool> $values */
    public static function fromArray(string $root, array $values): self
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        $config = new self($root, $normalized);
        $config->validate();

        return $config;
    }

    /** @param array<string, string|int|bool> $overrides */
    public function with(array $overrides): self
    {
        $values = $this->values;
        foreach ($overrides as $key => $value) {
            $values[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        $config = new self($this->root, $values);
        $config->validate();

        return $config;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        if ($relative !== '' && ($relative[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $relative) === 1)) {
            return $relative;
        }

        return $this->root . '/' . ltrim($relative, '/');
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? $default : trim($value);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->string($key);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = strtolower($this->string($key));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public function list(string $key, string $default = ''): array
    {
        $raw = $this->string($key, $default);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }

    public function env(): string
    {
        return $this->string('APP_ENV', 'production');
    }

    public function isProduction(): bool
    {
        return $this->env() === 'production';
    }

    public function isDevelopment(): bool
    {
        return $this->env() === 'development';
    }

    public function isTest(): bool
    {
        return $this->env() === 'test';
    }

    public function debug(): bool
    {
        return $this->isDevelopment() && $this->bool('APP_DEBUG');
    }

    public function appUrl(): string
    {
        return rtrim($this->string('APP_URL', 'http://127.0.0.1:8080'), '/');
    }

    public function timezone(): string
    {
        return $this->string('APP_TIMEZONE', 'Europe/Rome');
    }

    public function dbPath(): string
    {
        $path = $this->string('DB_PATH', 'var/db.sqlite');

        return $path === ':memory:' ? $path : $this->path($path);
    }

    public function varPath(string $sub = ''): string
    {
        return $this->root . '/var' . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public function wtbMode(): string
    {
        return $this->string('WTB_MODE', 'fixture');
    }

    /**
     * Controlli bloccanti all'avvio: meglio fermarsi subito che fare danni.
     */
    private function validate(): void
    {
        $env = $this->env();
        if (!in_array($env, ['development', 'production', 'test'], true)) {
            throw new ConfigException('APP_ENV deve essere development, production o test.');
        }
        $mode = $this->wtbMode();
        if (!in_array($mode, ['fixture', 'test', 'live'], true)) {
            throw new ConfigException('WTB_MODE deve essere fixture, test o live.');
        }
        if ($mode === 'live' && $env !== 'production') {
            throw new ConfigException('WTB_MODE=live è ammesso solo con APP_ENV=production (sul server di produzione).');
        }
        if (!in_array($this->string('WTB_ORDER_TRIGGER', 'on_confirm'), ['on_confirm', 'on_request', 'manual'], true)) {
            throw new ConfigException('WTB_ORDER_TRIGGER deve essere on_confirm, on_request o manual.');
        }
        if (!in_array($this->string('WTB_PAYMENT_METHOD', 'bank_transfer'), ['bank_transfer', 'balance'], true)) {
            throw new ConfigException('WTB_PAYMENT_METHOD deve essere bank_transfer o balance.');
        }
        if (!in_array($this->string('WTB_SHIP_TO_EMAIL', 'admin'), ['admin', 'customer'], true)) {
            throw new ConfigException('WTB_SHIP_TO_EMAIL deve essere admin o customer.');
        }
        if (!in_array($this->string('MAIL_TRANSPORT', 'log'), ['log', 'smtp', 'array'], true)) {
            throw new ConfigException('MAIL_TRANSPORT deve essere log o smtp.');
        }
    }
}
