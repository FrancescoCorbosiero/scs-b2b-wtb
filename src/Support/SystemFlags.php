<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stato di servizio persistente (tabella system_flags): avvisi che restano finché la causa non sparisce
 * (es. chiave del fornitore rifiutata, sync bloccato) e piccoli valori dei job (es. impronta dello stock).
 */
final class SystemFlags
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->db->value('SELECT value FROM system_flags WHERE key = :k', ['k' => $key]);

        return $value === null ? null : (string) $value;
    }

    /** @return array<string, mixed>|null */
    public function getJson(string $key): ?array
    {
        $value = $this->get($key);
        if ($value === null) {
            return null;
        }
        $data = json_decode($value, true);

        return is_array($data) ? $data : null;
    }

    public function set(string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO system_flags (key, value, updated_at) VALUES (:k, :v, :t)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            ['k' => $key, 'v' => $value, 't' => $this->clock->iso()],
        );
    }

    /** @param array<string, mixed> $value */
    public function setJson(string $key, array $value): void
    {
        $this->set($key, (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function clear(string $key): void
    {
        $this->db->run('DELETE FROM system_flags WHERE key = :k', ['k' => $key]);
    }

    public function updatedAt(string $key): ?string
    {
        $value = $this->db->value('SELECT updated_at FROM system_flags WHERE key = :k', ['k' => $key]);

        return $value === null ? null : (string) $value;
    }
}
