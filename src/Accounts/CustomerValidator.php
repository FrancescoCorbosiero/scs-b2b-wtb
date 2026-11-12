<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Shipping\CountryRepository;

/**
 * Validazione dei dati aziendali (richiesta di profilo, creazione da admin, profilo, checkout).
 * Restituisce i dati puliti e gli errori come chiavi di traduzione per campo.
 */
final class CustomerValidator
{
    public function __construct(private readonly CountryRepository $countries)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $fields campi da controllare
     * @return array{data: array<string, string>, errors: array<string, string>}
     */
    public function validate(array $input, array $fields): array
    {
        $data = [];
        $errors = [];
        $get = static fn (string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $country = strtoupper($get('country_code'));

        foreach ($fields as $field) {
            $value = $get($field);
            switch ($field) {
                case 'email':
                    $value = strtolower($value);
                    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || mb_strlen($value) > 190) {
                        $errors[$field] = 'validation.email';
                    }
                    break;
                case 'name':
                case 'company':
                case 'address_street':
                case 'address_city':
                    if ($value === '') {
                        $errors[$field] = 'validation.required';
                    } elseif (mb_strlen($value) > 150) {
                        $errors[$field] = 'validation.too_long';
                    }
                    break;
                case 'phone':
                    if ($value === '') {
                        $errors[$field] = 'validation.required';
                    } elseif (preg_match('/^\+?[0-9 ()\/.\-]{6,25}$/', $value) !== 1) {
                        $errors[$field] = 'validation.phone';
                    }
                    break;
                case 'address_zip':
                    if ($value === '') {
                        $errors[$field] = 'validation.required';
                    } elseif ($country === 'IT' && preg_match('/^\d{5}$/', $value) !== 1) {
                        $errors[$field] = 'validation.zip_it';
                    } elseif (mb_strlen($value) > 12) {
                        $errors[$field] = 'validation.too_long';
                    }
                    break;
                case 'address_province':
                    $value = strtoupper($value);
                    if ($country === 'IT' && preg_match('/^[A-Z]{2}$/', $value) !== 1) {
                        $errors[$field] = 'validation.province';
                    } elseif (mb_strlen($value) > 40) {
                        $errors[$field] = 'validation.too_long';
                    }
                    break;
                case 'country_code':
                    $value = $country;
                    if (!$this->countries->isServed($value)) {
                        $errors[$field] = 'validation.country';
                    }
                    break;
                case 'vat_number':
                    $normalized = $country !== '' ? VatNumber::normalize($value, $country) : null;
                    if ($value === '') {
                        $errors[$field] = 'validation.required';
                    } elseif ($normalized === null) {
                        $errors[$field] = 'validation.vat';
                    } else {
                        $value = $normalized;
                    }
                    break;
                case 'locale':
                    $value = in_array($value, ['it', 'en'], true) ? $value : 'it';
                    break;
                case 'notes':
                    if (mb_strlen($value) > 2000) {
                        $errors[$field] = 'validation.too_long';
                    }
                    break;
            }
            $data[$field] = $value;
        }

        return ['data' => $data, 'errors' => $errors];
    }
}
