<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Session;

/**
 * Dati della richiesta corrente per i template (sessione, cliente, area). Popolato dai middleware.
 */
final class ViewContext
{
    public ?Session $session = null;

    /** @var array<string, mixed>|null cliente collegato (riga di users, senza hash) */
    public ?array $customer = null;

    public bool $isAdmin = false;
    public string $adminName = '';
    public string $area = 'site';
    public string $path = '/';

    /** @var array<string, mixed> */
    public array $query = [];

    public bool $isHttps = false;

    /** Paia nel carrello (badge in intestazione). */
    public int $cartCount = 0;

    /** @var list<array{type: string, message: string}>|null messaggi flash, letti una volta per richiesta */
    public ?array $flashes = null;

    /** @var array<string, mixed>|null valori del modulo da ripresentare, letti una volta per richiesta */
    public ?array $oldInput = null;

    /** @var array<string, string>|null errori per campo, letti una volta per richiesta */
    public ?array $errors = null;

    public function reset(): void
    {
        $this->errors = null;
        $this->flashes = null;
        $this->oldInput = null;
        $this->session = null;
        $this->customer = null;
        $this->isAdmin = false;
        $this->adminName = '';
        $this->area = 'site';
        $this->path = '/';
        $this->query = [];
        $this->isHttps = false;
        $this->cartCount = 0;
    }
}
