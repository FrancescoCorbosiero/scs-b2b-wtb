<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Supplier\Wtb\WtbConfig;
use App\Support\Clock;
use App\Support\Db;
use Psr\Log\LoggerInterface;

/**
 * Proxy delle immagini prodotto (D-011, docs/03 § Immagini, docs/09 § anti-SSRF): nel browser solo URL
 * nostri (/img/<prodotto>/<n>-<w>.jpg). Si scarica solo da URL salvati dal sync, solo https, solo host
 * in whitelist, niente redirect, massimo 5 MB, solo immagini vere; file con nomi generati da noi.
 */
final class ImageService
{
    public const WIDTHS = [0, 400, 800];
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly Db $db,
        private readonly WtbConfig $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDir,
    ) {
    }

    /** URL pubblico (nostro dominio) con l'impronta della sorgente: cambia se cambia l'immagine. */
    public static function publicUrl(int $productId, int $position, string $sourceUrl, int $width = 800): string
    {
        return sprintf('/img/%d/%d-%d.jpg?v=%s', $productId, $position, $width, substr(sha1($sourceUrl), 0, 10));
    }

    /**
     * Percorso del file da servire (scaricandolo se serve), o null se l'immagine non è disponibile.
     */
    public function resolve(int $productId, int $position, int $width): ?string
    {
        if (!in_array($width, self::WIDTHS, true)) {
            return null;
        }
        $row = $this->db->one('SELECT source_url FROM product_images WHERE product_id = :p AND position = :n', ['p' => $productId, 'n' => $position]);
        if ($row === null) {
            return null;
        }
        $source = (string) $row['source_url'];
        $version = substr(sha1($source), 0, 10);
        $dir = $this->cacheDir . '/' . $productId;
        $target = sprintf('%s/%d-%d-%s.jpg', $dir, $position, $width, $version);
        if (is_file($target)) {
            return $target;
        }
        $original = sprintf('%s/%d-0-%s.jpg', $dir, $position, $version);
        if (!is_file($original)) {
            $bytes = $this->download($source);
            if ($bytes === null) {
                $this->mark($productId, $position, 'error', 'download non riuscito');

                return null;
            }
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $jpeg = $this->toJpeg($bytes, 0);
            if ($jpeg === null) {
                $this->mark($productId, $position, 'error', 'formato non valido');

                return null;
            }
            file_put_contents($original, $jpeg);
            $this->mark($productId, $position, 'ok', '');
        }
        if ($width === 0) {
            return $original;
        }
        $resized = $this->toJpeg((string) file_get_contents($original), $width);
        if ($resized === null) {
            return $original;
        }
        file_put_contents($target, $resized);

        return $target;
    }

    /** Scarica in anticipo le immagini non ancora in cache (job image_prefetch). */
    public function prefetch(int $limit): int
    {
        $done = 0;
        $rows = $this->db->all(
            "SELECT pi.product_id, pi.position FROM product_images pi JOIN products p ON p.id = pi.product_id
             WHERE pi.status = 'pending' AND p.is_active = 1 ORDER BY pi.position, pi.id LIMIT :l",
            ['l' => $limit],
        );
        foreach ($rows as $row) {
            if ($this->resolve((int) $row['product_id'], (int) $row['position'], 400) !== null) {
                $done++;
            }
        }

        return $done;
    }

    /** Toglie dalla cache le cartelle dei prodotti che non esistono più o sono fuori catalogo. */
    public function purgeOrphans(): int
    {
        $removed = 0;
        foreach (glob($this->cacheDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = (int) basename($dir);
            $active = $this->db->value('SELECT is_active FROM products WHERE id = :id', ['id' => $id]);
            if ($active === null || (int) $active === 0) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
                $removed++;
            }
        }

        return $removed;
    }

    private function download(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || !in_array($host, $this->config->imageHosts, true)) {
            $this->logger->warning('Immagine rifiutata: host non in whitelist', ['host' => $host]);

            return null;
        }
        if ($this->config->mode === 'fixture') {
            return $this->fixtureImage($url);
        }
        $headers = ['Accept: image/*'];
        if ($this->config->imageSendKey && $host === $this->config->host() && $this->config->apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $this->config->apiKey;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $type = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($ch, int $total, int $now): int => ($total > self::MAX_BYTES || $now > self::MAX_BYTES) ? 1 : 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200 || !str_starts_with(strtolower($type), 'image/') || strlen($body) > self::MAX_BYTES) {
            $this->logger->warning('Immagine non scaricata', ['status' => $status, 'type' => $type]);

            return null;
        }

        return $body;
    }

    /** Modalità fixture: nessuna rete, file locali in fixtures/wtb/images/. */
    private function fixtureImage(string $url): ?string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $file = $this->config->fixturesDir . '/images/' . $name;
        if (preg_match('/^[a-z0-9._-]+$/i', $name) !== 1 || !is_file($file)) {
            $file = $this->config->fixturesDir . '/images/placeholder.jpg';
        }

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** Ricodifica sempre in JPEG (via GD): niente file del fornitore serviti così come arrivano. */
    private function toJpeg(string $bytes, int $width): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return null;
        }
        if (!function_exists('imagecreatefromstring')) {
            return $info[2] === IMAGETYPE_JPEG ? $bytes : null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        [$w, $h] = [imagesx($image), imagesy($image)];
        if ($width > 0 && $w > $width) {
            $newH = max(1, (int) round($h * $width / $w));
            $resized = imagecreatetruecolor($width, $newH);
            imagefill($resized, 0, 0, (int) imagecolorallocate($resized, 255, 255, 255));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $newH, $w, $h);
            imagedestroy($image);
            $image = $resized;
        } elseif ($info[2] !== IMAGETYPE_JPEG) {
            $flat = imagecreatetruecolor($w, $h);
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);
            imagedestroy($image);
            $image = $flat;
        }
        ob_start();
        imagejpeg($image, null, 82);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function mark(int $productId, int $position, string $status, string $error): void
    {
        $this->db->run(
            'UPDATE product_images SET status = :s, error = :e, cached_at = :t WHERE product_id = :p AND position = :n',
            ['s' => $status, 'e' => $error, 't' => $status === 'ok' ? $this->clock->iso() : null, 'p' => $productId, 'n' => $position],
        );
    }
}
