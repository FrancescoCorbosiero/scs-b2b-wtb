<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Accounts\AdminAuth;
use App\Http\Req;
use App\Http\Responder;
use App\Http\ViewContext;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminAuth $auth,
        private readonly ViewContext $context,
        private readonly Translator $t,
    ) {
    }

    public function loginForm(Request $request, Response $response): Response
    {
        if ($this->context->isAdmin) {
            return $this->responder->redirect($response, '/admin');
        }

        return $this->responder->render($response, 'admin/login.twig', ['configured' => $this->auth->isConfigured()]);
    }

    public function login(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $name = mb_substr(Req::str($request, 'name'), 0, 60);
        $result = $this->auth->attempt((string) (Req::input($request)['password'] ?? ''), Req::ip($request));
        if ($result !== 'ok') {
            $session->flash('error', $this->t->get('admin.login.' . ($result === 'not_configured' || $result === 'locked' ? $result : 'invalid')));
            $session->flashInput(['name' => $name]);

            return $this->responder->redirect($response, '/admin/login');
        }
        $this->auth->login($session, $name);

        return $this->responder->redirect($response, '/admin');
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout(Req::session($request));

        return $this->responder->redirect($response, '/admin/login');
    }
}
