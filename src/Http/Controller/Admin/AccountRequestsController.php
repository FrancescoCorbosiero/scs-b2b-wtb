<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Accounts\AccountRequestService;
use App\Http\Req;
use App\Http\Responder;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /admin/richieste-profilo: "Approva" crea l'account e manda l'invito; "Rifiuta" archivia. */
final class AccountRequestsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AccountRequestService $requests,
        private readonly Translator $t,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $status = Req::query($request, 'stato', 'pending');
        $status = in_array($status, ['pending', 'approved', 'rejected', 'all'], true) ? $status : 'pending';

        return $this->responder->render($response, 'admin/requests/index.twig', [
            'requests' => $this->requests->list($status),
            'status' => $status,
        ]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $row = $this->requests->find((int) $args['id']) ?? throw new HttpNotFoundException($request);

        return $this->responder->render($response, 'admin/requests/show.twig', ['request' => $row]);
    }

    /** @param array<string, string> $args */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $result = $this->requests->approve($id, Req::adminName($request));
        $session = Req::session($request);
        if ($result['status'] === 'ok') {
            $session->flash(($result['mailed'] ?? false) ? 'success' : 'error', $this->t->get(($result['mailed'] ?? false) ? 'admin.requests.approved' : 'admin.requests.approved_not_mailed'));

            return $this->responder->redirect($response, '/admin/clienti/' . ($result['user_id'] ?? 0));
        }
        $session->flash('error', $this->t->get('admin.requests.error_' . $result['status']));

        return $this->responder->redirect($response, '/admin/richieste-profilo/' . $id);
    }

    /** @param array<string, string> $args */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $ok = $this->requests->reject((int) $args['id'], Req::adminName($request));
        Req::session($request)->flash($ok ? 'success' : 'error', $this->t->get($ok ? 'admin.requests.rejected' : 'admin.requests.error_not_pending'));

        return $this->responder->redirect($response, '/admin/richieste-profilo');
    }
}
