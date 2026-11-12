<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Testi in lang/: it.php è la fonte (default), en.php ha le stesse chiavi; se manca una chiave
 * in inglese si usa l'italiano. Parametri ":nome"; plurali "singolare|plurale".
 */
final class Translator
{
    public const LOCALES = ['it', 'en'];
    public const DEFAULT_LOCALE = 'it';

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    private string $locale = self::DEFAULT_LOCALE;

    public function __construct(private readonly string $directory)
    {
    }

    public static function normalize(?string $locale): string
    {
        $locale = strtolower(substr(trim((string) $locale), 0, 2));

        return in_array($locale, self::LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = self::normalize($locale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, string|int|float|null> $params */
    public function get(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale === null ? $this->locale : self::normalize($locale);
        $text = $this->catalog($locale)[$key] ?? $this->catalog(self::DEFAULT_LOCALE)[$key] ?? $key;

        return $this->interpolate($text, $params);
    }

    /** @param array<string, string|int|float|null> $params */
    public function choice(string $key, int $count, array $params = [], ?string $locale = null): string
    {
        $forms = explode('|', $this->get($key, [], $locale));
        $text = $count === 1 || count($forms) === 1 ? $forms[0] : $forms[1];

        return $this->interpolate($text, $params + ['count' => $count]);
    }

    public function has(string $key, string $locale): bool
    {
        return isset($this->catalog(self::normalize($locale))[$key]);
    }

    /** @return list<string> */
    public function keys(string $locale): array
    {
        return array_keys($this->catalog(self::normalize($locale)));
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $file = $this->directory . '/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            $this->catalogs[$locale] = self::flatten(is_array($data) ? $data : []);
        }

        return $this->catalogs[$locale];
    }

    /**
     * @param array<mixed> $data
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out += self::flatten($value, $full);
            } else {
                $out[$full] = (string) $value;
            }
        }

        return $out;
    }

    /** @param array<string, string|int|float|null> $params */
    private function interpolate(string $text, array $params): string
    {
        if ($params === []) {
            return $text;
        }
        uksort($params, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($params as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }
}
