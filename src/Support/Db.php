<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

/**
 * Accesso a SQLite via PDO, sempre con prepared statements (docs/09).
 * Nomi di tabelle e colonne passati ai metodi di comodo vengono SOLO dal codice.
 */
final class Db
{
    private int $depth = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function open(string $path): self
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }
        $pdo->exec('PRAGMA foreign_keys = ON');

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<int|string, mixed> $params */
    public function int(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        return array_values($this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );
        $this->run($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $sets[] = sprintf('%s = :set_%s', $column, $column);
            $params['set_' . $column] = $value;
        }
        foreach ($whereParams as $key => $value) {
            $params[ltrim($key, ':')] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);

        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Esegue $fn in una transazione (BEGIN IMMEDIATE: niente deadlock in scrittura con WAL).
     * Le chiamate annidate partecipano alla transazione esterna.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn($this);
            } finally {
                $this->depth--;
            }
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $fn($this);
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}
