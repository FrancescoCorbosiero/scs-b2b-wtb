<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Salvataggio delle sessioni in SQLite. A DB si tiene solo l'hash dell'id (un dump del
 * database non permette di rubare sessioni vive).
 */
final class SessionStore
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly int $lifetime,
    ) {
    }

    public function lifetime(): int
    {
        return $this->lifetime;
    }

    public function load(string $scope, ?string $id): Session
    {
        if ($id !== null && preg_match('/^[a-f0-9]{64}$/', $id) === 1) {
            $row = $this->db->one(
                'SELECT data, expires_at FROM sessions WHERE id_hash = :h AND scope = :s',
                ['h' => hash('sha256', $id), 's' => $scope],
            );
            if ($row !== null && (string) $row['expires_at'] > $this->clock->iso()) {
                $data = json_decode((string) $row['data'], true);

                return new Session($id, is_array($data) ? $data : [], false);
            }
        }

        return new Session(Session::newId(), [], true);
    }

    /**
     * Salva se ci sono modifiche o se la scadenza va allungata.
     *
     * @param string|null $previousId id prima di un'eventuale rigenerazione
     */
    public function save(string $scope, Session $session, ?string $previousId): void
    {
        $now = $this->clock->iso();
        $expires = Clock::toIso($this->clock->now()->modify('+' . $this->lifetime . ' seconds'));
        if ($previousId !== null && $previousId !== $session->id()) {
            $this->db->run('DELETE FROM sessions WHERE id_hash = :h', ['h' => hash('sha256', $previousId)]);
        }
        if ($session->all() === [] && !$session->wasDestroyed()) {
            if (!$session->isNew()) {
                $this->db->run('DELETE FROM sessions WHERE id_hash = :h', ['h' => hash('sha256', $session->id())]);
            }

            return;
        }
        $this->db->run(
            'INSERT INTO sessions (id_hash, scope, data, updated_at, expires_at) VALUES (:h, :s, :d, :now, :exp)
             ON CONFLICT (id_hash) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at, expires_at = excluded.expires_at',
            [
                'h' => hash('sha256', $session->id()),
                's' => $scope,
                'd' => json_encode($session->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'now' => $now,
                'exp' => $expires,
            ],
        );
    }

    /** Allunga la scadenza senza riscrivere i dati (al massimo una volta ogni 5 minuti). */
    public function touch(string $scope, Session $session): void
    {
        $this->db->run(
            'UPDATE sessions SET expires_at = :exp, updated_at = :now
             WHERE id_hash = :h AND scope = :s AND updated_at < :threshold',
            [
                'exp' => Clock::toIso($this->clock->now()->modify('+' . $this->lifetime . ' seconds')),
                'now' => $this->clock->iso(),
                'h' => hash('sha256', $session->id()),
                's' => $scope,
                'threshold' => $this->clock->isoAgo(300),
            ],
        );
    }

    public function purgeExpired(): int
    {
        return $this->db->run('DELETE FROM sessions WHERE expires_at < :now', ['now' => $this->clock->iso()])->rowCount();
    }
}
