<?php

declare(strict_types=1);

namespace App\Admin;

use App\Catalog\ProductSync;
use App\Supplier\Wtb\WtbClient;
use App\Support\Db;
use App\Support\SystemFlags;
use App\Support\Translator;

/**
 * Avvisi della dashboard admin (docs/07): restano finché la causa non sparisce.
 * Ogni modulo aggiunge qui i suoi controlli.
 */
final class Alerts
{
    public function __construct(
        private readonly Db $db,
        private readonly SystemFlags $flags,
        private readonly Translator $t,
    ) {
    }

    /** @return list<array{level: string, title: string, text: string, href: string}> */
    public function all(): array
    {
        $alerts = [];
        $auth = $this->flags->getJson(WtbClient::FLAG_AUTH_ERROR);
        if ($auth !== null) {
            $alerts[] = [
                'level' => 'error',
                'title' => $this->t->get('admin.alerts.auth_title'),
                'text' => $this->t->get('admin.alerts.auth_text', ['since' => (string) $this->flags->updatedAt(WtbClient::FLAG_AUTH_ERROR)]),
                'href' => '/admin/sync',
            ];
        }
        $blocked = $this->flags->getJson(ProductSync::FLAG_BLOCKED);
        if ($blocked !== null) {
            $alerts[] = [
                'level' => 'error',
                'title' => $this->t->get('admin.alerts.blocked_title'),
                'text' => $this->t->get('admin.alerts.blocked_text', [
                    'previous' => (int) ($blocked['previous'] ?? 0),
                    'now' => (int) ($blocked['now'] ?? 0),
                    'percent' => (int) ($blocked['max_drop_percent'] ?? 0),
                ]),
                'href' => '/admin/sync',
            ];
        }
        foreach (['products_sync', 'stock_sync'] as $job) {
            $last = $this->db->one("SELECT status, message, started_at FROM job_runs WHERE job = :j AND status != 'skipped' ORDER BY id DESC LIMIT 1", ['j' => $job]);
            if ($last !== null && $last['status'] === 'error' && $blocked === null) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => $this->t->get('admin.alerts.job_error', ['job' => $this->t->get('admin.sync.jobs.' . $job)]),
                    'text' => mb_substr((string) $last['message'], 0, 300),
                    'href' => '/admin/sync',
                ];
            }
        }

        return $alerts;
    }
}
