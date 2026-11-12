<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Accounts\CustomerValidator;
use App\Accounts\Passwords;
use App\Accounts\UserRepository;
use App\Http\Req;
use App\Http\Responder;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Area "Il mio account": profilo (precompila il checkout) e cambio password.
 * Ragione sociale, partita IVA e paese di fatturazione li cambia solo l'admin: decidono l'IVA (docs/04).
 */
final class AccountController
{
    public const EDITABLE = ['name', 'phone', 'address_street', 'address_city', 'address_zip', 'address_province', 'locale'];

    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
        private readonly CustomerValidator $validator,
        private readonly Translator $t,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'account/profile.twig', [
            'user' => Req::requireCustomer($request),
            'min_length' => Passwords::MIN_LENGTH,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $user = Req::requireCustomer($request);
        $input = Req::input($request);
        $input['country_code'] = $user['country_code'];
        $result = $this->validator->validate($input, self::EDITABLE);
        if ($result['errors'] !== []) {
            $session->flash('error', $this->t->get('validation.fix_errors'));
            $session->flashInput($input);
            $session->flashErrors($result['errors']);

            return $this->responder->redirect($response, '/account');
        }
        $this->users->update((int) $user['id'], $result['data']);
        $session->set('locale', $result['data']['locale']);
        $session->flash('success', $this->t->get('account.saved', [], $result['data']['locale']));

        return $this->responder->redirect($response, '/account');
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $user = Req::requireCustomer($request);
        $full = $this->users->find((int) $user['id']);
        $input = Req::input($request);
        if (!Passwords::verify((string) ($input['current_password'] ?? ''), $full['password_hash'] ?? null)) {
            $session->flash('error', $this->t->get('account.current_password_wrong'));

            return $this->responder->redirect($response, '/account#password');
        }
        $error = Passwords::policyError((string) ($input['password'] ?? ''), (string) ($input['password_confirm'] ?? ''));
        if ($error !== null) {
            $session->flash('error', $this->t->get($error, ['min' => Passwords::MIN_LENGTH]));

            return $this->responder->redirect($response, '/account#password');
        }
        $this->users->setPassword((int) $user['id'], (string) $input['password']);
        $session->regenerate();
        $session->flash('success', $this->t->get('account.password_changed'));

        return $this->responder->redirect($response, '/account');
    }
}
