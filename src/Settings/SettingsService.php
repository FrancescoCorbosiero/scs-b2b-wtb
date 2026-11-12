<?php

declare(strict_types=1);

namespace App\Settings;

use App\Support\Clock;
use App\Support\Db;

/**
 * Legge e scrive le impostazioni del registro; ogni modifica finisce in settings_audit,
 * così "disfare" è sempre possibile (docs/02).
 */
final class SettingsService
{
    /** @var array<string, string>|null valori salvati (testo), caricati una volta per richiesta */
    private ?array $stored = null;

    /** @var list<callable(string): void> */
    private array $listeners = [];

    public function __construct(
        private readonly Db $db,
        private readonly Registry $registry,
        private readonly Clock $clock,
    ) {
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    /** Chiamato dopo ogni modifica, con la chiave cambiata (es. per ricalcolare i prezzi). */
    public function onChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function get(string $key): mixed
    {
        $definition = $this->registry->get($key);
        $stored = $this->stored()[$key] ?? null;

        return $stored === null ? $definition->default : $definition->decode($stored);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        return array_values(array_map('strval', (array) $this->get($key)));
    }

    public function isCustomized(string $key): bool
    {
        return isset($this->stored()[$key]);
    }

    /**
     * Salva un valore scritto dall'admin nel modulo.
     *
     * @throws InvalidSetting
     */
    public function setFromInput(string $key, string $input, string $by): void
    {
        $this->setValue($key, $this->registry->get($key)->parseInput($input), $by);
    }

    /** Salva un valore già tipizzato (validato di nuovo). */
    public function setValue(string $key, mixed $value, string $by): void
    {
        $definition = $this->registry->get($key);
        $definition->check($value);
        $encoded = $definition->encode($value);
        $old = $this->stored()[$key] ?? null;
        if ($old === $encoded) {
            return;
        }
        if ($old === null && $encoded === $definition->encode($definition->default)) {
            return;
        }
        $now = $this->clock->iso();
        $this->db->transaction(function (Db $db) use ($key, $encoded, $old, $by, $now): void {
            $db->run(
                'INSERT INTO settings (key, value, updated_at, updated_by) VALUES (:key, :value, :now, :by)
                 ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at, updated_by = excluded.updated_by',
                ['key' => $key, 'value' => $encoded, 'now' => $now, 'by' => $by],
            );
            $db->insert('settings_audit', [
                'key' => $key, 'old_value' => $old, 'new_value' => $encoded, 'changed_at' => $now, 'changed_by' => $by,
            ]);
        });
        $this->stored = null;
        $this->notify($key);
    }

    /** Torna al valore di default (cancella il valore salvato). */
    public function reset(string $key, string $by): void
    {
        $this->registry->get($key);
        $old = $this->stored()[$key] ?? null;
        if ($old === null) {
            return;
        }
        $now = $this->clock->iso();
        $this->db->transaction(function (Db $db) use ($key, $old, $by, $now): void {
            $db->run('DELETE FROM settings WHERE key = :key', ['key' => $key]);
            $db->insert('settings_audit', [
                'key' => $key, 'old_value' => $old, 'new_value' => null, 'changed_at' => $now, 'changed_by' => $by,
            ]);
        });
        $this->stored = null;
        $this->notify($key);
    }

    /**
     * Storico delle modifiche, più recenti prima.
     *
     * @return list<array<string, mixed>>
     */
    public function audit(?string $key = null, int $limit = 100): array
    {
        if ($key !== null) {
            return $this->db->all(
                'SELECT * FROM settings_audit WHERE key = :key ORDER BY id DESC LIMIT :limit',
                ['key' => $key, 'limit' => $limit],
            );
        }

        return $this->db->all('SELECT * FROM settings_audit ORDER BY id DESC LIMIT :limit', ['limit' => $limit]);
    }

    /** Testo leggibile di un valore salvato nello storico (NULL = default). */
    public function describeStored(string $key, ?string $stored): string
    {
        if (!$this->registry->has($key)) {
            return (string) $stored;
        }
        $definition = $this->registry->get($key);
        $value = $stored === null ? $definition->default : $definition->decode($stored);
        $text = match ($definition->type) {
            'bool' => $value ? 'sì' : 'no',
            'money' => \App\Support\Money::format((int) $value),
            'enum' => $definition->options[(string) $value] ?? (string) $value,
            'list' => implode(', ', (array) $value),
            'decimal' => str_replace('.', ',', (string) $value),
            default => (string) $value,
        };

        return $stored === null ? $text . ' (default)' : $text;
    }

    /** @return array<string, string> */
    private function stored(): array
    {
        if ($this->stored === null) {
            $this->stored = [];
            foreach ($this->db->all('SELECT key, value FROM settings') as $row) {
                $this->stored[(string) $row['key']] = (string) $row['value'];
            }
        }

        return $this->stored;
    }

    private function notify(string $key): void
    {
        foreach ($this->listeners as $listener) {
            $listener($key);
        }
    }
}
