<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Accounts\CustomerService;
use App\Accounts\CustomerValidator;
use App\Accounts\UserRepository;
use App\Http\Req;
use App\Http\Responder;
use App\Support\Translator;
use App\Support\Xlsx;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /admin/clienti: crea con invito, modifica, reinvia invito, reset, disattiva, export Excel (mai hash o token). */
final class CustomersController
{
    public const FIELDS = [
        'email', 'company', 'vat_number', 'name', 'phone', 'address_street', 'address_city',
        'address_zip', 'address_province', 'country_code', 'locale',
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
        private readonly CustomerValidator $validator,
        private readonly CustomerService $service,
        private readonly Translator $t,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = Req::query($request, 'q');
        $status = Req::query($request, 'stato');

        return $this->responder->render($response, 'admin/customers/index.twig', [
            'customers' => $this->users->search($q, $status),
            'q' => $q,
            'status' => $status,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'admin/customers/form.twig', ['customer' => null]);
    }

    public function store(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $input = Req::input($request);
        $result = $this->validator->validate($input, self::FIELDS);
        $errors = $result['errors'];
        if (!isset($errors['email']) && $this->users->findByEmail($result['data']['email']) !== null) {
            $errors['email'] = 'validation.email_taken';
        }
        if ($errors !== []) {
            $session->flash('error', $this->t->get('validation.fix_errors'));
            $session->flashInput($input);
            $session->flashErrors($errors);

            return $this->responder->redirect($response, '/admin/clienti/nuovo');
        }
        $data = $result['data'] + ['admin_notes' => mb_substr(Req::str($request, 'admin_notes'), 0, 2000)];
        $created = $this->service->createWithInvite($data, Req::adminName($request));
        $session->flash($created['mailed'] ? 'success' : 'error', $created['mailed']
            ? $this->t->get('admin.customers.created_invited', ['email' => $data['email']])
            : $this->t->get('admin.customers.created_not_mailed'));

        return $this->responder->redirect($response, '/admin/clienti/' . $created['id']);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $customer = $this->users->find((int) $args['id']) ?? throw new HttpNotFoundException($request);

        return $this->responder->render($response, 'admin/customers/form.twig', [
            'customer' => UserRepository::safe($customer),
        ]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $customer = $this->users->find($id) ?? throw new HttpNotFoundException($request);
        $session = Req::session($request);
        $input = Req::input($request);
        $result = $this->validator->validate($input, self::FIELDS);
        $errors = $result['errors'];
        $other = isset($errors['email']) ? null : $this->users->findByEmail($result['data']['email']);
        if ($other !== null && (int) $other['id'] !== $id) {
            $errors['email'] = 'validation.email_taken';
        }
        if ($errors !== []) {
            $session->flash('error', $this->t->get('validation.fix_errors'));
            $session->flashInput($input);
            $session->flashErrors($errors);

            return $this->responder->redirect($response, '/admin/clienti/' . $id);
        }
        $this->users->update($id, $result['data'] + ['admin_notes' => mb_substr(Req::str($request, 'admin_notes'), 0, 2000)]);
        $session->flash('success', $this->t->get('admin.customers.saved'));

        return $this->responder->redirect($response, '/admin/clienti/' . (int) $customer['id']);
    }

    /** @param array<string, string> $args */
    public function invite(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $this->users->find($id) ?? throw new HttpNotFoundException($request);
        $ok = $this->service->sendInvite($id);
        Req::session($request)->flash($ok ? 'success' : 'error', $this->t->get($ok ? 'admin.customers.invite_sent' : 'admin.customers.mail_failed'));

        return $this->responder->redirect($response, '/admin/clienti/' . $id);
    }

    /** @param array<string, string> $args */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $this->users->find($id) ?? throw new HttpNotFoundException($request);
        $ok = $this->service->sendReset($id);
        Req::session($request)->flash($ok ? 'success' : 'error', $this->t->get($ok ? 'admin.customers.reset_sent' : 'admin.customers.mail_failed'));

        return $this->responder->redirect($response, '/admin/clienti/' . $id);
    }

    /** @param array<string, string> $args */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $customer = $this->users->find($id) ?? throw new HttpNotFoundException($request);
        $active = (int) $customer['is_active'] !== 1;
        $this->users->setActive($id, $active);
        Req::session($request)->flash('success', $this->t->get($active ? 'admin.customers.activated' : 'admin.customers.deactivated'));

        return $this->responder->redirect($response, '/admin/clienti/' . $id);
    }

    public function export(Request $request, Response $response): Response
    {
        $rows = [[
            'ID', 'Ragione sociale', 'Partita IVA', 'Referente', 'Email', 'Telefono', 'Indirizzo', 'CAP', 'Città',
            'Provincia', 'Paese', 'Lingua', 'Stato', 'Creato il', 'Ultimo accesso',
        ]];
        foreach ($this->users->search() as $c) {
            $rows[] = [
                (int) $c['id'], (string) $c['company'], (string) $c['vat_number'], (string) $c['name'], (string) $c['email'],
                (string) $c['phone'], (string) $c['address_street'], (string) $c['address_zip'], (string) $c['address_city'],
                (string) $c['address_province'], (string) $c['country_code'], (string) $c['locale'],
                (int) $c['is_active'] !== 1 ? 'disattivato' : ((int) $c['has_password'] === 1 ? 'attivo' : 'invitato'),
                substr((string) $c['created_at'], 0, 10), substr((string) ($c['last_login_at'] ?? ''), 0, 10),
            ];
        }

        return $this->responder->download($response, Xlsx::build('Clienti', $rows), 'clienti-' . gmdate('Y-m-d') . '.xlsx', Xlsx::MIME);
    }
}
