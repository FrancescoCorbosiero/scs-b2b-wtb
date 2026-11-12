<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Catalog\ImageService;
use App\Http\Req;
use App\Http\Responder;
use App\Pricing\MarginResolver;
use App\Settings\SettingsService;
use App\Support\Db;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /admin/prodotti: catalogo con costi, prezzi e margini (solo admin) e flag "In evidenza". */
final class ProductsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Db $db,
        private readonly SettingsService $settings,
        private readonly Translator $t,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = Req::query($request, 'q');
        $where = '1 = 1';
        $params = [];
        if ($q !== '') {
            $where = "search_text LIKE :q ESCAPE '\\'";
            $params['q'] = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
        }
        if (Req::query($request, 'evidenza') === '1') {
            $where .= ' AND is_featured = 1';
        }
        $products = $this->db->all(
            'SELECT id, sku, name, brand, line, is_active, in_feed, is_featured, total_available, min_price_cents, min_price_regime, last_seen_at
             FROM products WHERE ' . $where . ' ORDER BY is_active DESC, total_available = 0, name COLLATE NOCASE LIMIT 500',
            $params,
        );

        return $this->responder->render($response, 'admin/products/index.twig', ['products' => $products, 'q' => $q, 'featured' => Req::query($request, 'evidenza') === '1']);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $product = $this->db->one('SELECT * FROM products WHERE id = :id', ['id' => (int) $args['id']]) ?? throw new HttpNotFoundException($request);
        $vat = $this->settings->int('tax.vat_rate_it');
        $offers = array_map(static fn (array $o): array => $o + [
            'margin' => $o['sale_price_cents'] === null ? null : (int) $o['sale_price_cents'] - (int) $o['cost_cents'],
            'net' => $o['sale_price_cents'] === null ? null : MarginResolver::netMarginEstimate((int) $o['sale_price_cents'], (int) $o['cost_cents'], (string) $o['vat_regime'], $vat),
        ], $this->db->all(
            'SELECT v.size_eu, v.size_us, v.size_uk, v.wtb_variant_id, v.is_active AS variant_active, o.*, r.match_type, r.match_value
             FROM variants v JOIN offers o ON o.variant_id = v.id LEFT JOIN margin_rules r ON r.id = o.margin_rule_id
             WHERE v.product_id = :p ORDER BY v.size_sort, v.id, o.vat_regime, o.stock_type DESC',
            ['p' => $product['id']],
        ));
        $images = array_map(
            static fn (array $i): array => $i + ['url' => ImageService::publicUrl((int) $product['id'], (int) $i['position'], (string) $i['source_url'], 400)],
            $this->db->all('SELECT * FROM product_images WHERE product_id = :p ORDER BY position', ['p' => $product['id']]),
        );

        return $this->responder->render($response, 'admin/products/show.twig', [
            'product' => $product + ['category_path_list' => (array) json_decode((string) $product['category_path'], true)],
            'offers' => $offers,
            'images' => $images,
        ]);
    }

    /** @param array<string, string> $args */
    public function feature(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $product = $this->db->one('SELECT is_featured FROM products WHERE id = :id', ['id' => $id]) ?? throw new HttpNotFoundException($request);
        $this->db->run('UPDATE products SET is_featured = :f WHERE id = :id', ['f' => (int) $product['is_featured'] === 1 ? 0 : 1, 'id' => $id]);
        Req::session($request)->flash('success', $this->t->get((int) $product['is_featured'] === 1 ? 'admin.products.unfeatured' : 'admin.products.featured'));
        $back = Req::safeNext(Req::str($request, 'back'), '/admin/prodotti/' . $id);

        return $this->responder->redirect($response, $back);
    }
}
