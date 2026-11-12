<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Support\Clock;
use App\Support\Db;

/** Clienti (tabella users). L'hash della password non esce mai da qui verso viste ed export. */
final class UserRepository
{
    public const PROFILE_FIELDS = [
        'email', 'name', 'company', 'vat_number', 'phone', 'address_street', 'address_city',
        'address_zip', 'address_province', 'country_code', 'locale',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = :e', ['e' => trim($email)]);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->clock->iso();
        $row = array_intersect_key($data, array_flip(self::PROFILE_FIELDS)) + ['locale' => 'it'];
        $row['admin_notes'] = (string) ($data['admin_notes'] ?? '');
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $row['is_active'] = 1;

        return $this->db->insert('users', $row);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $allowed = array_merge(self::PROFILE_FIELDS, ['admin_notes']);
        $row = array_intersect_key($data, array_flip($allowed));
        if ($row === []) {
            return;
        }
        $row['updated_at'] = $this->clock->iso();
        $this->db->update('users', $row, 'id = :id', ['id' => $id]);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->db->update('users', ['password_hash' => Passwords::hash($password), 'updated_at' => $this->clock->iso()], 'id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update('users', ['is_active' => $active ? 1 : 0, 'updated_at' => $this->clock->iso()], 'id = :id', ['id' => $id]);
    }

    public function touchLogin(int $id): void
    {
        $this->db->update('users', ['last_login_at' => $this->clock->iso()], 'id = :id', ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $q = '', string $status = ''): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(email LIKE :q OR company LIKE :q OR name LIKE :q OR vat_number LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status === 'active') {
            $where[] = 'is_active = 1 AND password_hash IS NOT NULL';
        } elseif ($status === 'invited') {
            $where[] = 'is_active = 1 AND password_hash IS NULL';
        } elseif ($status === 'inactive') {
            $where[] = 'is_active = 0';
        }

        return $this->db->all(
            'SELECT id, email, name, company, vat_number, phone, address_street, address_city, address_zip,
                    address_province, country_code, locale, is_active, password_hash IS NOT NULL AS has_password,
                    created_at, updated_at, last_login_at
             FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY company COLLATE NOCASE, id',
            $params,
        );
    }

    /** Toglie dalla riga i campi che non devono mai arrivare a viste, sessione o export. @param array<string, mixed> $user @return array<string, mixed> */
    public static function safe(array $user): array
    {
        $user['has_password'] = isset($user['password_hash']) && $user['password_hash'] !== '';
        unset($user['password_hash']);

        return $user;
    }
}
