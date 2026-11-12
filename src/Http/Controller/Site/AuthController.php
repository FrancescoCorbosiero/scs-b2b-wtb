<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Accounts\CustomerAuth;
use App\Accounts\CustomerService;
use App\Accounts\Passwords;
use App\Accounts\TokenService;
use App\Http\Req;
use App\Http\Responder;
use App\Support\RateLimiter;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Login, logout, password dimenticata e impostazione della password da link monouso. */
final class AuthController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CustomerAuth $auth,
        private readonly CustomerService $customers,
        private readonly TokenService $tokens,
        private readonly RateLimiter $limiter,
        private readonly Translator $t,
    ) {
    }

    public function loginForm(Request $request, Response $response): Response
    {
        if (Req::customer($request) !== null) {
            return $this->responder->redirect($response, Req::safeNext(Req::query($request, 'next'), '/account'));
        }

        return $this->responder->render($response, 'auth/login.twig', [
            'next' => Req::safeNext(Req::query($request, 'next'), ''),
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $email = Req::str($request, 'email');
        $result = $this->auth->attempt($email, Req::str($request, 'password'), Req::ip($request));
        $next = Req::safeNext(Req::str($request, 'next'), '/account');
        if ($result['status'] !== 'ok' || !isset($result['user'])) {
            $session->flash('error', $this->t->get($result['status'] === 'locked' ? 'auth.locked' : 'auth.invalid'));
            $session->flashInput(['email' => $email]);

            return $this->responder->redirect($response, '/login' . ($next !== '/account' ? '?next=' . rawurlencode($next) : ''));
        }
        $this->auth->login($session, (int) $result['user']['id']);
        $session->set('locale', (string) $result['user']['locale']);

        return $this->responder->redirect($response, $next);
    }

    public function logout(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $this->auth->logout($session);
        $session->flash('success', $this->t->get('auth.logged_out'));

        return $this->responder->redirect($response, '/');
    }

    public function forgotForm(Request $request, Response $response): Response
    {
        return $this->responder->render($response, 'auth/forgot.twig');
    }

    public function forgot(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $ip = Req::ip($request);
        if (!$this->limiter->tooMany('password_reset_ip', $ip, 10, 3600)) {
            $this->limiter->hit('password_reset_ip', $ip);
            $email = Req::str($request, 'email');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $this->customers->requestReset($email);
            }
        }
        // Risposta neutra, sempre uguale.
        $session->flash('success', $this->t->get('auth.reset_sent'));

        return $this->responder->redirect($response, '/password-dimenticata');
    }

    public function setPasswordForm(Request $request, Response $response): Response
    {
        $token = Req::query($request, 'token');
        $row = $this->tokens->peek($token);

        return $this->responder->render($response, 'auth/set_password.twig', [
            'token' => $row === null ? '' : $token,
            'purpose' => $row['purpose'] ?? null,
            'valid' => $row !== null,
            'min_length' => Passwords::MIN_LENGTH,
        ]);
    }

    public function setPassword(Request $request, Response $response): Response
    {
        $session = Req::session($request);
        $token = Req::str($request, 'token');
        $password = (string) (Req::input($request)['password'] ?? '');
        $confirm = (string) (Req::input($request)['password_confirm'] ?? '');
        $error = Passwords::policyError($password, $confirm);
        if ($error !== null) {
            $session->flash('error', $this->t->get($error, ['min' => Passwords::MIN_LENGTH]));

            return $this->responder->redirect($response, '/account/imposta-password?token=' . rawurlencode($token));
        }
        $userId = $this->customers->completeWithToken($token, $password);
        if ($userId === null) {
            $session->flash('error', $this->t->get('auth.link_invalid'));

            return $this->responder->redirect($response, '/password-dimenticata');
        }
        $this->auth->login($session, $userId);
        $session->flash('success', $this->t->get('auth.password_set'));

        return $this->responder->redirect($response, '/account');
    }
}
