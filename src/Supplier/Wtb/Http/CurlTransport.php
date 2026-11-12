<?php

declare(strict_types=1);

namespace App\Supplier\Wtb\Http;

/** Trasporto HTTP con curl: niente redirect (la chiave non deve mai finire su un altro host). */
final class CurlTransport implements Transport
{
    private const MAX_BODY = 64 * 1024 * 1024;

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): TransportResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportException('curl non disponibile');
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($ch, int $dlTotal, int $dlNow): int => $dlNow > self::MAX_BODY ? 1 : 0,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TransportException($error === '' ? 'errore di rete' : $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new TransportResponse($status, $responseHeaders, (string) $result);
    }
}
