<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Catalog\ProductSync;
use App\Http\Req;
use App\Http\Responder;
use App\Jobs\JobRunner;
use App\Jobs\Scheduler;
use App\Supplier\Wtb\WtbClient;
use App\Supplier\Wtb\WtbConfig;
use App\Supplier\Wtb\WtbMapper;
use App\Support\Db;
use App\Support\SystemFlags;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /admin/sync: ultimi job, "Sincronizza ora", profilo e tariffe del fornitore (solo admin). */
final class SyncController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Scheduler $scheduler,
        private readonly JobRunner $runner,
        private readonly Db $db,
        private readonly SystemFlags $flags,
        private readonly WtbConfig $config,
        private readonly Translator $t,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $jobs = [];
        foreach ($this->scheduler->jobs() as $job) {
            $last = $this->db->one('SELECT * FROM job_runs WHERE job = :j ORDER BY id DESC LIMIT 1', ['j' => $job->name()]);
            $jobs[] = [
                'name' => $job->name(),
                'label' => $this->t->get('admin.sync.jobs.' . $job->name()),
                'interval' => $job->interval(),
                'last' => $last === null ? null : $last + ['counts_array' => (array) json_decode((string) $last['counts'], true)],
            ];
        }
        $runs = $this->db->all('SELECT * FROM job_runs ORDER BY id DESC LIMIT 40');
        $snapshot = $this->db->one("SELECT payload, fetched_at FROM wtb_snapshots WHERE kind = 'shipping' ORDER BY id DESC LIMIT 1");
        $profile = null;
        if ($snapshot !== null) {
            $data = json_decode((string) $snapshot['payload'], true);
            if (is_array($data)) {
                $profile = (new WtbMapper())->shipping($data);
            }
        }

        return $this->responder->render($response, 'admin/sync/index.twig', [
            'jobs' => $jobs,
            'runs' => $runs,
            'profile' => $profile,
            'snapshot_at' => $snapshot['fetched_at'] ?? null,
            'auth_error' => $this->flags->getJson(WtbClient::FLAG_AUTH_ERROR),
            'blocked' => $this->flags->getJson(ProductSync::FLAG_BLOCKED),
            'mode' => $this->config->mode,
            'base_url' => $this->config->baseUrl,
            'counts' => [
                'products' => $this->db->int('SELECT COUNT(*) FROM products WHERE is_active = 1'),
                'available' => $this->db->int('SELECT COUNT(*) FROM products WHERE is_active = 1 AND total_available > 0'),
                'variants' => $this->db->int('SELECT COUNT(*) FROM variants WHERE is_active = 1'),
                'offers' => $this->db->int('SELECT COUNT(*) FROM offers WHERE available > 0'),
                'images_pending' => $this->db->int("SELECT COUNT(*) FROM product_images WHERE status = 'pending'"),
                'images_error' => $this->db->int("SELECT COUNT(*) FROM product_images WHERE status = 'error'"),
            ],
        ]);
    }

    /** @param array<string, string> $args */
    public function run(Request $request, Response $response, array $args): Response
    {
        $job = $this->scheduler->job($args['job']) ?? throw new HttpNotFoundException($request);
        @set_time_limit(600);
        $outcome = $this->runner->run($job, 'admin', Req::str($request, 'force') === '1');
        $session = Req::session($request);
        $session->flash(
            $outcome->status === 'error' ? 'error' : 'success',
            $this->t->get('admin.sync.jobs.' . $job->name()) . ': ' . strtok($outcome->message, "\n"),
        );

        return $this->responder->redirect($response, '/admin/sync');
    }
}
