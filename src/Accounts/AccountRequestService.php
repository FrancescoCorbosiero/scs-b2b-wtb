<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Settings\SettingsService;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\Mail\Mailer;
use App\Support\RateLimiter;

/**
 * Richieste di profilo da /richiedi-accesso (docs/07): CSRF, honeypot, max 3/ora per IP, niente doppioni.
 * L'admin approva (crea l'account e manda l'invito) o rifiuta (archivia).
 */
final class AccountRequestService
{
    public const FIELDS = [
        'company', 'vat_number', 'name', 'email', 'phone', 'address_street', 'address_city', 'address_zip',
        'address_province', 'country_code', 'locale', 'notes',
    ];
    public const MAX_PER_HOUR = 3;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly CustomerValidator $validator,
        private readonly UserRepository $users,
        private readonly CustomerService $customers,
        private readonly RateLimiter $limiter,
        private readonly Mailer $mailer,
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status: 'ok'|'invalid'|'duplicate'|'limited', errors?: array<string, string>}
     */
    public function submit(array $input, string $ip, string $userAgent): array
    {
        // Honeypot: un campo nascosto che solo i bot compilano. Risposta uguale a un invio riuscito.
        if (is_string($input['website'] ?? null) && trim($input['website']) !== '') {
            return ['status' => 'ok'];
        }
        if ($this->limiter->tooMany('account_request', $ip, self::MAX_PER_HOUR, 3600)) {
            return ['status' => 'limited'];
        }
        $result = $this->validator->validate($input, self::FIELDS);
        if ($result['errors'] !== []) {
            return ['status' => 'invalid', 'errors' => $result['errors']];
        }
        $data = $result['data'];
        $pending = $this->db->int(
            "SELECT COUNT(*) FROM account_requests WHERE status = 'pending' AND (email = :e OR vat_number = :v)",
            ['e' => $data['email'], 'v' => $data['vat_number']],
        );
        if ($pending > 0 || $this->users->findByEmail($data['email']) !== null) {
            return ['status' => 'duplicate'];
        }
        $this->limiter->hit('account_request', $ip);
        $id = $this->db->insert('account_requests', $data + [
            'status' => 'pending',
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => $this->clock->iso(),
        ]);
        $request = $this->find($id) ?? $data;
        $this->mailer->sendTemplate('account_request_admin', $this->settings->list('company.admin_emails'), [
            'request' => $request,
            'link' => $this->config->appUrl() . '/admin/richieste-profilo/' . $id,
        ], 'it', [], $data['email']);
        $this->mailer->sendTemplate('account_request_received', [$data['email']], ['request' => $request], $data['locale']);

        return ['status' => 'ok'];
    }

    /** @return list<array<string, mixed>> */
    public function list(string $status = 'pending'): array
    {
        if ($status === 'all') {
            return $this->db->all('SELECT * FROM account_requests ORDER BY id DESC LIMIT 500');
        }

        return $this->db->all('SELECT * FROM account_requests WHERE status = :s ORDER BY id DESC LIMIT 500', ['s' => $status]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM account_requests WHERE id = :id', ['id' => $id]);
    }

    public function pendingCount(): int
    {
        return $this->db->int("SELECT COUNT(*) FROM account_requests WHERE status = 'pending'");
    }

    /** @return array{status: 'ok'|'not_pending'|'email_taken'|'invalid', user_id?: int, mailed?: bool} */
    public function approve(int $id, string $by): array
    {
        $request = $this->find($id);
        if ($request === null || $request['status'] !== 'pending') {
            return ['status' => 'not_pending'];
        }
        $check = $this->validator->validate($request, array_diff(self::FIELDS, ['notes']));
        if ($check['errors'] !== []) {
            return ['status' => 'invalid'];
        }
        if ($this->users->findByEmail((string) $request['email']) !== null) {
            return ['status' => 'email_taken'];
        }
        $created = $this->customers->createWithInvite($check['data'] + ['admin_notes' => (string) $request['notes']], $by);
        $this->db->update('account_requests', [
            'status' => 'approved', 'reviewed_at' => $this->clock->iso(), 'reviewed_by' => $by, 'user_id' => $created['id'],
        ], 'id = :id', ['id' => $id]);

        return ['status' => 'ok', 'user_id' => $created['id'], 'mailed' => $created['mailed']];
    }

    public function reject(int $id, string $by): bool
    {
        return $this->db->update('account_requests', [
            'status' => 'rejected', 'reviewed_at' => $this->clock->iso(), 'reviewed_by' => $by,
        ], "id = :id AND status = 'pending'", ['id' => $id]) === 1;
    }
}
