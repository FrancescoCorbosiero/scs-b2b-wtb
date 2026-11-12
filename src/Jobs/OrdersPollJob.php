<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Orders\SupplierOrderUpdater;
use App\Supplier\Exception\SupplierException;
use App\Supplier\SupplierOrders;
use App\Support\Clock;
use App\Support\Db;
use App\Support\Deferred;

/**
 * Rete di sicurezza ai webhook (docs/06): gli ordini al fornitore live non conclusi e fermi da più di 2 ore si
 * rileggono col reference e passano dalle stesse transizioni dei webhook. Solo in modalità live.
 */
final class OrdersPollJob implements Job
{
    public const STALE_SECONDS = 7200;
    public const MAX_PER_RUN = 50;

    public function __construct(
        private readonly SupplierOrders $supplier,
        private readonly SupplierOrderUpdater $updater,
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Deferred $deferred,
        private readonly int $interval,
    ) {
    }

    public function name(): string
    {
        return 'orders_poll';
    }

    public function interval(): int
    {
        return $this->interval;
    }

    public function run(bool $force = false): JobOutcome
    {
        if ($this->supplier->mode() !== 'live') {
            return new JobOutcome('ok', ['checked' => 0], 'Modalità ' . strtoupper($this->supplier->mode()) . ': nessun ordine da rileggere (solo live).');
        }
        $stale = $this->clock->isoAgo(self::STALE_SECONDS);
        $rows = $this->db->all(
            "SELECT * FROM supplier_orders
             WHERE mode = 'live'
               AND (status IN ('pending', 'received', 'confirmed', 'paid') OR (status = 'error' AND created_at > :recent))
               AND created_at > :since
               AND (:force = 1 OR COALESCE(last_event_at, updated_at) < :stale)
             ORDER BY id LIMIT " . self::MAX_PER_RUN,
            ['recent' => $this->clock->isoAgo(2 * 86400), 'since' => $this->clock->isoAgo(60 * 86400), 'force' => $force ? 1 : 0, 'stale' => $stale],
        );
        $counts = ['checked' => 0, 'applied' => 0, 'stale' => 0, 'not_found' => 0, 'errors' => 0];
        $lastError = '';
        foreach ($rows as $row) {
            $counts['checked']++;
            try {
                $data = $this->supplier->findByReference((string) $row['reference']);
            } catch (SupplierException $e) {
                $counts['errors']++;
                $lastError = $e->getMessage();
                continue;
            }
            if ($data === null) {
                $counts['not_found']++;
                if ($row['status'] === 'pending' && (string) $row['created_at'] < $this->clock->isoAgo(3600)) {
                    // Creazione mai confermata e assente presso il fornitore: l'admin può ritentare in sicurezza.
                    $this->db->update('supplier_orders', [
                        'status' => 'error',
                        'last_error' => 'Creazione non confermata e ordine assente presso il fornitore: ritenta (stesso reference).',
                        'updated_at' => $this->clock->iso(),
                    ], "id = :id AND status = 'pending'", ['id' => $row['id']]);
                }
                continue;
            }
            $result = $this->updater->apply($data, 'poll');
            $result['outcome'] === 'applied' ? $counts['applied']++ : $counts['stale']++;
        }
        $this->deferred->run();
        $status = $counts['errors'] > 0 && $counts['errors'] === $counts['checked'] ? 'error' : 'ok';

        return new JobOutcome($status, $counts, $status === 'error'
            ? 'Fornitore non raggiungibile: ' . mb_substr($lastError, 0, 300)
            : sprintf('%d ordini riletti, %d aggiornati.', $counts['checked'], $counts['applied']));
    }
}
