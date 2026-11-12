<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\Model\ShippingProfile;
use App\Supplier\Model\StockRow;
use App\Supplier\Model\SupplierOffer;
use App\Supplier\Model\SupplierOrderData;
use App\Supplier\Model\SupplierProduct;
use App\Supplier\Model\SupplierQuote;
use App\Supplier\Model\SupplierQuoteLine;
use App\Supplier\Model\SupplierVariant;
use App\Supplier\Model\SupplierZone;
use App\Support\Money;

/**
 * JSON del fornitore → modelli interni (docs/03 § Mappatura). Dati validati, mai fidati (docs/09):
 * un errore di struttura ferma tutto; una riga sbagliata si scarta con un avviso.
 * Prezzi da stringa a centesimi senza float.
 */
final class WtbMapper
{
    public const GENDERS = ['men', 'women', 'kids', 'unisex'];

    /** @var list<string> */
    private array $warnings = [];

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function resetWarnings(): void
    {
        $this->warnings = [];
    }

    public function warn(string $message): void
    {
        if (count($this->warnings) < 500) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Una pagina di /products/.
     *
     * @param array<mixed> $page
     * @return array{products: list<SupplierProduct>, has_more: bool}
     */
    public function productsPage(array $page): array
    {
        self::requireEur($page, '/products/');
        if (!isset($page['results']) || !is_array($page['results']) || !array_is_list($page['results'])) {
            throw new SupplierFormatException('Pagina di /products/ senza "results".');
        }
        if (!is_bool($page['has_more'] ?? null)) {
            throw new SupplierFormatException('Pagina di /products/ senza "has_more".');
        }
        $products = [];
        foreach ($page['results'] as $i => $row) {
            $product = $this->product($row, 'products[' . $i . ']');
            if ($product !== null) {
                $products[] = $product;
            }
        }

        return ['products' => $products, 'has_more' => $page['has_more']];
    }

    public function product(mixed $row, string $where = 'product'): ?SupplierProduct
    {
        if (!is_array($row)) {
            $this->warn($where . ': non è un oggetto, scartato.');

            return null;
        }
        $sku = self::text($row['sku'] ?? null, 64);
        $name = self::text($row['name'] ?? null, 255);
        if ($sku === null || $sku === '' || $name === null || $name === '' || preg_match('/[\x00-\x1F]/', $sku) === 1) {
            $this->warn($where . ': SKU o nome mancanti, prodotto scartato.');

            return null;
        }
        $gender = strtolower(self::text($row['gender'] ?? '', 20) ?? '');
        if ($gender !== '' && !in_array($gender, self::GENDERS, true)) {
            $this->warn($sku . ': genere sconosciuto "' . $gender . '", ignorato.');
            $gender = '';
        }
        $path = [];
        foreach (is_array($row['category_path'] ?? null) ? $row['category_path'] : [] as $segment) {
            $segment = self::text($segment, 120);
            if ($segment !== null && $segment !== '' && count($path) < 10) {
                $path[] = $segment;
            }
        }
        $images = [];
        foreach (is_array($row['images'] ?? null) ? $row['images'] : [] as $url) {
            if (is_string($url) && preg_match('#^https://[^\s"<>]+$#', $url) === 1 && strlen($url) <= 1000 && count($images) < 20) {
                $images[] = $url;
            } else {
                $this->warn($sku . ': URL immagine non valido, ignorato.');
            }
        }
        $variants = [];
        $seen = [];
        if (!is_array($row['sizes'] ?? null)) {
            $this->warn($sku . ': nessuna taglia, prodotto scartato.');

            return null;
        }
        foreach ($row['sizes'] as $j => $size) {
            $variant = $this->variant($size, $sku . ' taglia ' . $j);
            if ($variant === null) {
                continue;
            }
            if (isset($seen[$variant->variantId])) {
                $this->warn($sku . ': variante ' . $variant->variantId . ' ripetuta, ignorata.');
                continue;
            }
            $seen[$variant->variantId] = true;
            $variants[] = $variant;
        }

        return new SupplierProduct(
            $sku,
            $name,
            self::text($row['brand'] ?? '', 120) ?? '',
            self::text($row['category'] ?? '', 120) ?? '',
            self::text($row['line'] ?? '', 120) ?? '',
            $gender,
            $path,
            mb_substr(self::text($row['description'] ?? '', 20000) ?? '', 0, 5000),
            $images,
            $variants,
        );
    }

    public function variant(mixed $size, string $where): ?SupplierVariant
    {
        if (!is_array($size) || !is_int($size['variant_id'] ?? null) || $size['variant_id'] <= 0) {
            $this->warn($where . ': variant_id mancante, taglia scartata.');

            return null;
        }
        $eu = self::size($size['size_eu'] ?? null);
        if ($eu === null || $eu === '') {
            $this->warn($where . ': taglia EU mancante, scartata.');

            return null;
        }
        $offers = [];
        foreach (is_array($size['offers'] ?? null) ? $size['offers'] : [] as $k => $offer) {
            $parsed = $this->offer($offer, $where . ' offerta ' . $k);
            if ($parsed !== null) {
                $offers[] = $parsed;
            }
        }
        $ean = self::text($size['ean'] ?? '', 20) ?? '';

        return new SupplierVariant(
            $size['variant_id'],
            $eu,
            self::size($size['size_us'] ?? '') ?? '',
            self::size($size['size_uk'] ?? '') ?? '',
            preg_match('/^\d{0,20}$/', $ean) === 1 ? $ean : '',
            $offers,
        );
    }

    public function offer(mixed $offer, string $where): ?SupplierOffer
    {
        if (!is_array($offer)) {
            $this->warn($where . ': non è un oggetto, scartata.');

            return null;
        }
        $regime = $offer['vat_regime'] ?? null;
        $stockType = $offer['stock_type'] ?? null;
        if (!in_array($regime, SupplierOffer::REGIMES, true) || !in_array($stockType, SupplierOffer::STOCK_TYPES, true)) {
            $this->warn($where . ': regime o tipo di stock sconosciuto (' . self::describe($regime) . '/' . self::describe($stockType) . '), scartata.');

            return null;
        }
        $available = $offer['available'] ?? null;
        $cost = self::money($offer['price'] ?? null);
        if (!is_int($available) || $available < 0 || $cost === null || $cost < 0) {
            $this->warn($where . ': disponibilità o prezzo non validi, scartata.');

            return null;
        }

        return new SupplierOffer($regime, $stockType, $available, $cost, self::text($offer['ships_in_days'] ?? '', 20) ?? '');
    }

    /**
     * @param array<mixed> $data
     * @return list<StockRow>
     */
    public function stock(array $data): array
    {
        self::requireEur($data, '/stock/');
        if (!isset($data['results']) || !is_array($data['results']) || !array_is_list($data['results'])) {
            throw new SupplierFormatException('Risposta di /stock/ senza "results".');
        }
        $rows = [];
        foreach ($data['results'] as $i => $row) {
            $where = 'stock[' . $i . ']';
            if (!is_array($row) || !is_int($row['variant_id'] ?? null)) {
                $this->warn($where . ': riga senza variant_id, scartata.');
                continue;
            }
            $offer = $this->offer($row, $where);
            $sku = self::text($row['sku'] ?? null, 64);
            if ($offer === null || $sku === null || $sku === '') {
                continue;
            }
            $rows[] = new StockRow($sku, $row['variant_id'], self::size($row['size_eu'] ?? '') ?? '', $offer->regime, $offer->stockType, $offer->available, $offer->costCents, $offer->shipsInDays);
        }

        return $rows;
    }

    /** @param array<mixed> $data */
    public function shipping(array $data): ShippingProfile
    {
        if (isset($data['currency']) && $data['currency'] !== 'EUR') {
            throw new SupplierFormatException('Valuta delle tariffe diversa da EUR.');
        }
        if (!is_array($data['zones'] ?? null)) {
            throw new SupplierFormatException('Risposta di /shipping/ senza "zones".');
        }
        $zones = [];
        foreach ($data['zones'] as $i => $zone) {
            if (!is_array($zone) || !is_array($zone['countries'] ?? null)) {
                $this->warn('shipping.zones[' . $i . ']: zona non valida, scartata.');
                continue;
            }
            $countries = array_values(array_filter(array_map(
                static fn ($c): string => is_string($c) ? strtoupper($c) : '',
                $zone['countries'],
            ), static fn (string $c): bool => preg_match('/^[A-Z]{2}$/', $c) === 1));
            $zones[] = new SupplierZone(
                self::text($zone['name'] ?? '', 120) ?? '',
                $countries,
                self::money($zone['first_piece'] ?? null) ?? 0,
                self::money($zone['each_additional_piece'] ?? null) ?? 0,
                self::money($zone['failed_delivery_fee'] ?? null) ?? 0,
                ($zone['duties_paid_by_recipient'] ?? false) === true,
            );
        }
        $transport = [];
        $ext = is_array($data['extended_stock_transport'] ?? null) ? $data['extended_stock_transport'] : [];
        foreach (is_array($ext['by_pieces'] ?? null) ? $ext['by_pieces'] : [] as $step) {
            if (is_array($step) && is_int($step['pieces'] ?? null) && ($amount = self::money($step['amount'] ?? null)) !== null) {
                $transport[$step['pieces']] = $amount;
            }
        }
        ksort($transport);
        $freeFrom = is_int($ext['free_from_pieces'] ?? null) ? $ext['free_from_pieces'] : null;
        $minOrder = null;
        foreach (['min_order_pieces', 'min_order', 'minimum_order', 'min_pieces'] as $key) {
            if (is_int($data[$key] ?? null)) {
                $minOrder = $data[$key];
                break;
            }
        }

        return new ShippingProfile(self::text($data['profile'] ?? '', 40) ?? '', $zones, $transport, $freeFrom, $minOrder, $data);
    }

    /** @param array<mixed> $data */
    public function quote(array $data): SupplierQuote
    {
        self::requireEur($data, '/quote/');
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (!is_array($line) || !is_int($line['quantity'] ?? null)) {
                throw new SupplierFormatException('Riga del preventivo non valida.');
            }
            $regime = $line['vat_regime'] ?? '';
            $stockType = $line['stock_type'] ?? '';
            $cost = self::money($line['unit_price'] ?? null);
            if (!in_array($regime, SupplierOffer::REGIMES, true) || !in_array($stockType, SupplierOffer::STOCK_TYPES, true) || $cost === null) {
                throw new SupplierFormatException('Riga del preventivo con regime, tipo di stock o prezzo non validi.');
            }
            $lines[] = new SupplierQuoteLine(
                self::text($line['sku'] ?? '', 64) ?? '',
                self::size($line['size_eu'] ?? '') ?? '',
                $regime,
                $line['quantity'],
                $cost,
                $stockType,
            );
        }
        $required = static function (string $field) use ($data): int {
            $value = self::money($data[$field] ?? null);
            if ($value === null) {
                throw new SupplierFormatException('Preventivo senza "' . $field . '".');
            }

            return $value;
        };
        $shipping = $required('shipping');
        $extended = self::money($data['extended_stock_transport'] ?? null) ?? 0;

        return new SupplierQuote(
            strtoupper(self::text($data['ship_to_country'] ?? '', 2) ?? ''),
            is_int($data['pieces'] ?? null) ? $data['pieces'] : array_sum(array_map(static fn (SupplierQuoteLine $l): int => $l->quantity, $lines)),
            $required('subtotal'),
            $shipping,
            $extended,
            self::money($data['shipping_total'] ?? null) ?? $shipping + $extended,
            $required('vat'),
            $required('total'),
            self::text($data['vat_note'] ?? '', 1000) ?? '',
            $lines,
            $data,
        );
    }

    /** @param array<mixed> $data */
    public function order(array $data): SupplierOrderData
    {
        if (isset($data['currency']) && $data['currency'] !== 'EUR') {
            throw new SupplierFormatException('Valuta dell\'ordine diversa da EUR.');
        }
        $status = is_string($data['status'] ?? null) ? $data['status'] : '';
        if (!in_array($status, SupplierOrderData::STATUSES, true)) {
            throw new SupplierFormatException('Stato dell\'ordine sconosciuto: ' . self::describe($status) . '.');
        }
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lines[] = [
                'sku' => self::text($line['sku'] ?? '', 64) ?? '',
                'name' => self::text($line['name'] ?? '', 255) ?? '',
                'size_eu' => self::size($line['size_eu'] ?? '') ?? '',
                'vat_regime' => is_string($line['vat_regime'] ?? null) ? $line['vat_regime'] : '',
                'quantity' => is_int($line['quantity'] ?? null) ? $line['quantity'] : 0,
                'unit_cost_cents' => self::money($line['unit_price'] ?? null) ?? 0,
            ];
        }
        $tracking = [];
        foreach (is_array($data['tracking'] ?? null) ? $data['tracking'] : [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $number = self::text($t['number'] ?? '', 100) ?? '';
            if ($number === '') {
                continue;
            }
            $tracking[] = [
                'carrier' => self::text($t['carrier'] ?? '', 60) ?? '',
                'number' => $number,
                'url' => is_string($t['url'] ?? null) && preg_match('#^https?://[^\s"<>]+$#', $t['url']) === 1 ? $t['url'] : '',
            ];
        }
        $instructions = is_array($data['payment_instructions'] ?? null) ? $data['payment_instructions'] : null;

        return new SupplierOrderData(
            is_int($data['id'] ?? null) ? $data['id'] : null,
            self::text($data['number'] ?? '', 60) ?? '',
            self::text($data['reference'] ?? '', 60) ?? '',
            $status,
            ($data['is_paid'] ?? false) === true,
            self::text($data['payment_method'] ?? '', 40) ?? '',
            self::money($data['subtotal'] ?? null) ?? 0,
            self::money($data['shipping'] ?? null) ?? 0,
            self::money($data['vat'] ?? null) ?? 0,
            self::money($data['total'] ?? null) ?? 0,
            $lines,
            $tracking,
            $instructions,
            ($data['test'] ?? false) === true,
            self::text($data['created_at'] ?? '', 40) ?? '',
            $data,
        );
    }

    /** Importo del fornitore → centesimi: "63.84", "0", 12 (M0: sono stringhe, a volte senza decimali). */
    public static function money(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value) || preg_match('/^\d{1,9}(\.\d{1,2})?$/', trim($value)) !== 1) {
            return null;
        }

        return Money::parse(trim($value));
    }

    /** @param array<mixed> $data */
    private static function requireEur(array $data, string $where): void
    {
        if (array_key_exists('currency', $data) && $data['currency'] !== 'EUR') {
            throw new SupplierFormatException('Valuta di ' . $where . ' diversa da EUR: sync annullato.');
        }
    }

    private static function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /** Le taglie restano stringhe; se arrivano numeri si convertono senza perdere i decimali. */
    private static function size(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        return self::text($value, 20);
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : gettype($value);
    }
}
