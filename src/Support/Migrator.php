<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Migrazioni solo in avanti: file SQL numerati in database/migrations/, registrati in `migrations`.
 */
final class Migrator
{
    public function __construct(
        private readonly Db $db,
        private readonly string $directory,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<string> versioni applicate in questa esecuzione */
    public function migrate(): array
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        );
        $applied = array_flip(array_map('strval', $this->db->column('SELECT version FROM migrations')));
        $done = [];
        foreach ($this->files() as $version => $file) {
            if (isset($applied[$version])) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $ok = $this->db->transaction(function (Db $db) use ($sql, $version): bool {
                // Ricontrollo dentro la transazione: due processi che migrano insieme non applicano due volte.
                if ($db->value('SELECT 1 FROM migrations WHERE version = :v', ['v' => $version]) !== null) {
                    return false;
                }
                $db->pdo()->exec($sql);
                $db->insert('migrations', ['version' => $version, 'applied_at' => $this->clock->iso()]);

                return true;
            });
            if ($ok) {
                $done[] = $version;
            }
        }

        return $done;
    }

    /** @return array<string, string> versione => percorso */
    public function files(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            if (preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', basename($file), $m) !== 1) {
                throw new \RuntimeException('Nome di migrazione non valido: ' . basename($file));
            }
            $out[$m[1]] = $file;
        }

        return $out;
    }
}
