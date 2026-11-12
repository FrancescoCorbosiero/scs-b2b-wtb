<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Support\Config;
use App\Support\Mail\Mailer;
use Psr\Log\LoggerInterface;

/**
 * Ciclo di vita dell'account cliente: creazione con invito, reinvio, reset password con risposta neutra.
 * La password non viaggia mai per email: si manda solo un link monouso (docs/07).
 */
final class CustomerService
{
    public const RESET_MAX_PER_HOUR = 3;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenService $tokens,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crea l'account (attivo, senza password) e manda l'invito.
     *
     * @param array<string, mixed> $data dati già validati
     * @return array{id: int, mailed: bool}
     */
    public function createWithInvite(array $data, string $by): array
    {
        $id = $this->users->create($data);
        $this->logger->info('Cliente creato', ['user_id' => $id, 'by' => $by]);

        return ['id' => $id, 'mailed' => $this->sendInvite($id)];
    }

    public function sendInvite(int $userId): bool
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return false;
        }
        $token = $this->tokens->issue($userId, 'invite');

        return $this->mailer->sendTemplate('invite', [(string) $user['email']], [
            'user' => UserRepository::safe($user),
            'link' => $this->config->appUrl() . '/account/imposta-password?token=' . $token,
            'hours' => intdiv(TokenService::INVITE_TTL, 3600),
        ], (string) $user['locale']);
    }

    /** Risposta sempre neutra: non dice mai se l'email esiste. */
    public function requestReset(string $email): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || (int) $user['is_active'] !== 1) {
            return;
        }
        if ($this->tokens->countRecent((int) $user['id'], 'reset', 3600) >= self::RESET_MAX_PER_HOUR) {
            $this->logger->notice('Reset password: limite orario raggiunto', ['user_id' => $user['id']]);

            return;
        }
        $this->sendReset((int) $user['id']);
    }

    public function sendReset(int $userId): bool
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return false;
        }
        // Un invito mai completato si rinnova come invito (stesso link, durata più lunga).
        if ($user['password_hash'] === null) {
            return $this->sendInvite($userId);
        }
        $token = $this->tokens->issue($userId, 'reset');

        return $this->mailer->sendTemplate('password_reset', [(string) $user['email']], [
            'user' => UserRepository::safe($user),
            'link' => $this->config->appUrl() . '/account/imposta-password?token=' . $token,
            'hours' => intdiv(TokenService::RESET_TTL, 3600),
        ], (string) $user['locale']);
    }

    /** Imposta la password dal link monouso; restituisce l'id utente o null se il link non vale più. */
    public function completeWithToken(string $token, string $password): ?int
    {
        $row = $this->tokens->peek($token);
        if ($row === null) {
            return null;
        }
        $user = $this->users->find((int) $row['user_id']);
        if ($user === null || (int) $user['is_active'] !== 1) {
            return null;
        }
        $userId = $this->tokens->consume($token);
        if ($userId === null) {
            return null;
        }
        $this->users->setPassword($userId, $password);
        $this->logger->info('Password impostata da link', ['user_id' => $userId, 'purpose' => $row['purpose']]);

        return $userId;
    }
}
