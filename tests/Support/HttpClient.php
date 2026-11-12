<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\SessionMiddleware;
use App\Support\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** Client HTTP in-process: cookie tra una richiesta e l'altra, CSRF automatico sui POST. */
final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    public ResponseInterface $last;

    public function __construct(
        private readonly TestApp $app,
        private string $ip,
    ) {
    }

    public function setIp(string $ip): void
    {
        $this->ip = $ip;
    }

    /** @param array<string, string> $headers @param array<string, string> $server */
    public function get(string $uri, array $headers = [], array $server = []): ResponseInterface
    {
        return $this->request('GET', $uri, null, $headers, $server);
    }

    /**
     * POST di un modulo; aggiunge il token CSRF della sessione se manca.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $data = [], array $headers = [], bool $withCsrf = true): ResponseInterface
    {
        if ($withCsrf && !array_key_exists('_csrf', $data)) {
            $data['_csrf'] = $this->csrf(str_starts_with($uri, '/admin') ? 'admin' : 'site');
        }

        return $this->request('POST', $uri, $data, $headers);
    }

    /** @param array<mixed> $json @param array<string, string> $headers */
    public function postJson(string $uri, array $json, array $headers = [], bool $withCsrf = true): ResponseInterface
    {
        if ($withCsrf) {
            $headers['X-CSRF-Token'] = $this->csrf(str_starts_with($uri, '/admin') ? 'admin' : 'site');
        }
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';

        return $this->request('POST', $uri, (string) json_encode($json), $headers);
    }

    /** @param array<string, string> $headers */
    public function postRaw(string $uri, string $body, array $headers = []): ResponseInterface
    {
        return $this->request('POST', $uri, $body, $headers);
    }

    public function csrf(string $scope): string
    {
        $token = $this->session($scope)->get('_csrf');
        // Una pagina con un modulo crea il token (dopo il login la sessione viene rigenerata e il token cambia).
        foreach ($scope === 'admin' ? ['/admin/login', '/admin'] : ['/login', '/account', '/'] as $page) {
            if (is_string($token)) {
                break;
            }
            $this->get($page);
            $token = $this->session($scope)->get('_csrf');
        }
        if (!is_string($token)) {
            throw new \RuntimeException('Token CSRF non disponibile.');
        }

        return $token;
    }

    public function session(string $scope = 'site'): \App\Support\Session
    {
        $cookie = $this->cookies[$scope === 'admin' ? SessionMiddleware::ADMIN_COOKIE : SessionMiddleware::SITE_COOKIE] ?? null;

        return $this->app->get(SessionStore::class)->load($scope, $cookie);
    }

    public function loginAdmin(): void
    {
        $response = $this->post('/admin/login', ['password' => TestApp::ADMIN_PASSWORD, 'name' => 'Test']);
        if ($response->getHeaderLine('Location') !== '/admin') {
            throw new \RuntimeException('Login admin non riuscito.');
        }
    }

    public function loginCustomer(string $email = 'cliente@example.com', string $password = 'customer-password'): void
    {
        $response = $this->post('/login', ['email' => $email, 'password' => $password]);
        if ($response->getStatusCode() !== 303 || str_starts_with($response->getHeaderLine('Location'), '/login')) {
            throw new \RuntimeException('Login cliente non riuscito.');
        }
    }

    public function body(): string
    {
        return (string) $this->last->getBody();
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function request(string $method, string $uri, array|string|null $body, array $headers = [], array $server = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://b2b.example.test' . $uri, $server + ['REMOTE_ADDR' => $this->ip]);
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query)->withCookieParams($this->cookies);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (is_array($body)) {
            $request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        } elseif (is_string($body)) {
            $request = $request->withBody((new StreamFactory())->createStream($body));
        }
        $response = $this->app->app()->handle($request);
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            $parts = explode(';', $cookie);
            [$name, $value] = array_pad(explode('=', trim($parts[0]), 2), 2, '');
            if (str_contains(strtolower($cookie), 'max-age=0') || $value === '') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
        $this->app->get(\App\Support\Deferred::class)->run();
        $response->getBody()->rewind();

        return $this->last = $response;
    }
}
