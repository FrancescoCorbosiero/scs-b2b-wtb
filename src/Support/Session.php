<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sessione server-side: i dati stanno nel database (tabella sessions), il browser ha solo un id casuale.
 * Dati serializzati in JSON (mai unserialize). Il carrello vive qui (docs/08).
 */
final class Session
{
    private bool $dirty = false;
    private bool $regenerated = false;
    private bool $destroyed = false;

    /** @param array<string, mixed> $data */
    public function __construct(
        private string $id,
        private array $data,
        private readonly bool $isNew,
    ) {
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        if (($this->data[$key] ?? null) === $value && array_key_exists($key, $this->data)) {
            return;
        }
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** Nuovo id (al login): l'id vecchio smette di valere. */
    public function regenerate(): void
    {
        $this->id = self::newId();
        $this->regenerated = true;
        $this->dirty = true;
    }

    /** Svuota tutto e cambia id (al logout). */
    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
        $this->regenerate();
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function wasRegenerated(): bool
    {
        return $this->regenerated;
    }

    public function wasDestroyed(): bool
    {
        return $this->destroyed;
    }

    public function flash(string $type, string $message): void
    {
        /** @var list<array{type: string, message: string}> $flashes */
        $flashes = $this->get('_flash', []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $flashes);
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array
    {
        /** @var list<array{type: string, message: string}> $flashes */
        $flashes = $this->get('_flash', []);
        if ($flashes !== []) {
            $this->remove('_flash');
        }

        return $flashes;
    }

    /**
     * Valori del modulo da ripresentare dopo un errore di validazione (mai le password).
     *
     * @param array<string, mixed> $input
     */
    public function flashInput(array $input): void
    {
        unset($input['_csrf'], $input['password'], $input['password_confirm'], $input['current_password']);
        $this->set('_old', $input);
    }

    /**
     * Errori per campo (chiavi di traduzione) da mostrare accanto ai campi dopo un redirect.
     *
     * @param array<string, string> $errors
     */
    public function flashErrors(array $errors): void
    {
        $this->set('_errors', $errors);
    }

    /** @return array<string, string> */
    public function takeErrors(): array
    {
        $errors = $this->get('_errors', []);
        if ($errors !== []) {
            $this->remove('_errors');
        }

        return is_array($errors) ? array_map('strval', $errors) : [];
    }

    /** @return array<string, mixed> */
    public function takeOldInput(): array
    {
        $old = $this->get('_old', []);
        if ($old !== []) {
            $this->remove('_old');
        }

        return is_array($old) ? $old : [];
    }
}
