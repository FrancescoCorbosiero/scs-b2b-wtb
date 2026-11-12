<?php

declare(strict_types=1);

namespace Tests\Support;

/** Scorciatoie per i test di carrello e checkout. */
trait OrderHelpers
{
    /** Chiave di un'unità vendibile dato l'id variante del fornitore. */
    protected function unitKey(TestApp $app, int $wtbVariantId, string $regime = 'margin'): string
    {
        return $app->db->int('SELECT id FROM variants WHERE wtb_variant_id = :w', ['w' => $wtbVariantId]) . '-' . $regime;
    }

    /** @param array<int, int> $quantities id variante del fornitore => paia (regime margine) */
    protected function fillCart(TestApp $app, HttpClient $http, array $quantities, string $regime = 'margin'): void
    {
        $qty = [];
        foreach ($quantities as $wtb => $n) {
            $qty[$this->unitKey($app, $wtb, $regime)] = (string) $n;
        }
        $http->post('/carrello/aggiungi', ['qty' => $qty]);
    }

    /** @return array<string, string> */
    protected function checkoutForm(HttpClient $http, array $extra = []): array
    {
        $html = (string) $http->get('/checkout')->getBody();
        preg_match('/name="checkout_token" value="([a-f0-9]{32})"/', $html, $m);

        return $extra + [
            'checkout_token' => $m[1] ?? '',
            'contact_name' => 'Mario Rossi',
            'email' => 'cliente@example.com',
            'phone' => '+39 333 1234567',
            'ship_to_mode' => 'billing',
            'notes' => 'Consegna al mattino',
        ];
    }
}
