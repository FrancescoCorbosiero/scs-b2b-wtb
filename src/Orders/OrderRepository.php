<?php

declare(strict_types=1);

namespace App\Orders;

use App\Support\Db;

/** Lettura degli ordini. I campi riservati si tolgono con OrderPresenter prima di qualsiasi uscita al cliente. */
final class OrderRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM orders WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $orderId): array
    {
        return $this->db->all('SELECT * FROM order_lines WHERE order_id = :o ORDER BY vat_regime, name COLLATE NOCASE, id', ['o' => $orderId]);
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->all('SELECT * FROM orders WHERE user_id = :u ORDER BY id DESC', ['u' => $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $status, string $q, string $mode, int $limit = 200): array
    {
        $where = ['1 = 1'];
        $params = ['limit' => $limit];
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($mode !== '') {
            $where[] = 'mode = :mode';
            $params['mode'] = $mode;
        }
        if ($q !== '') {
            $where[] = '(CAST(id AS TEXT) = :qid OR company LIKE :q OR email LIKE :q OR vat_number LIKE :q OR receipt_number LIKE :q)';
            $params['qid'] = ltrim($q, '#');
            $params['q'] = '%' . $q . '%';
        }

        return $this->db->all('SELECT * FROM orders WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT :limit', $params);
    }
}
