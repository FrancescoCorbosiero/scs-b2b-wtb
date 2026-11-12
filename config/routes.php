<?php

declare(strict_types=1);

use App\Http\Controller\Admin\AccountRequestsController as AdminRequestsController;
use App\Http\Controller\Admin\AuthController as AdminAuthController;
use App\Http\Controller\Admin\MarginsController as AdminMarginsController;
use App\Http\Controller\Admin\OrdersController as AdminOrdersController;
use App\Http\Controller\Admin\ProductsController as AdminProductsController;
use App\Http\Controller\Admin\CustomersController as AdminCustomersController;
use App\Http\Controller\Admin\DashboardController as AdminDashboardController;
use App\Http\Controller\Admin\SettingsController as AdminSettingsController;
use App\Http\Controller\Admin\ShippingController as AdminShippingController;
use App\Http\Controller\Admin\SupplierOrdersController as AdminSupplierOrdersController;
use App\Http\Controller\Admin\SyncController as AdminSyncController;
use App\Http\Controller\Admin\WebhooksController as AdminWebhooksController;
use App\Http\Controller\SupplierWebhookController;
use App\Http\Controller\Site\AccountController;
use App\Http\Controller\Site\AuthController;
use App\Http\Controller\Site\CartController;
use App\Http\Controller\Site\CatalogController;
use App\Http\Controller\Site\CheckoutController;
use App\Http\Controller\Site\OrdersController;
use App\Http\Controller\Site\ShowcaseController;
use App\Http\Controller\Site\ImageController;
use App\Http\Controller\Site\PageController;
use App\Http\Middleware\RequireAdminMiddleware;
use App\Http\Middleware\RequireCustomerMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Mappa delle rotte. Pubbliche e indicizzabili: solo le pagine di presentazione (SecurityHeadersMiddleware::INDEXABLE).
 * Catalogo, carrello e account dietro login; admin dietro la sua sessione; webhook firmato.
 */
return static function (App $app): void {
    // --- Sito pubblico -----------------------------------------------------------------------
    $app->get('/', [PageController::class, 'home']);
    $app->get('/come-ordinare', [PageController::class, 'how']);
    $app->get('/spedizioni', [PageController::class, 'shipping']);
    $app->get('/contatti', [PageController::class, 'contacts']);
    $app->get('/richiedi-accesso', [PageController::class, 'requestForm']);
    $app->post('/richiedi-accesso', [PageController::class, 'requestSubmit']);
    $app->get('/richiedi-accesso/inviata', [PageController::class, 'requestSent']);
    $app->get('/healthz', [PageController::class, 'health']);
    // Immagini prodotto dal nostro dominio (D-011), pubbliche ma noindex.
    $app->get('/img/{product:\\d+}/{position:\\d+}-{width:0|400|800}.jpg', [ImageController::class, 'show']);

    // Webhook del fornitore: pubblico, senza sessione né CSRF, firmato (docs/06 § Webhook).
    $app->post('/webhooks/wtb', [SupplierWebhookController::class, 'receive']);

    // Vetrina: catalogo senza prezzi, aperto a tutti ma noindex.
    $app->get('/vetrina', [ShowcaseController::class, 'index']);
    $app->get('/vetrina/prodotto/{sku}', [ShowcaseController::class, 'product']);

    // --- Accesso clienti ---------------------------------------------------------------------
    $app->get('/login', [AuthController::class, 'loginForm']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout']);
    $app->get('/password-dimenticata', [AuthController::class, 'forgotForm']);
    $app->post('/password-dimenticata', [AuthController::class, 'forgot']);
    $app->get('/account/imposta-password', [AuthController::class, 'setPasswordForm']);
    $app->post('/account/imposta-password', [AuthController::class, 'setPassword']);

    // --- Area clienti (login) ----------------------------------------------------------------
    $app->group('', function (RouteCollectorProxy $g): void {
        $g->get('/catalogo', [CatalogController::class, 'index']);
        $g->get('/catalogo/export.xlsx', [CatalogController::class, 'export']);
        $g->get('/catalogo/prodotto/{sku}', [CatalogController::class, 'product']);

        $g->get('/carrello', [CartController::class, 'show']);
        $g->post('/carrello/aggiungi', [CartController::class, 'add']);
        $g->post('/carrello/aggiorna', [CartController::class, 'update']);
        $g->post('/carrello/svuota', [CartController::class, 'clear']);

        $g->get('/checkout', [CheckoutController::class, 'form']);
        $g->post('/checkout', [CheckoutController::class, 'submit']);
        $g->get('/checkout/completato/{id:\d+}', [CheckoutController::class, 'done']);

        $g->get('/account/ordini', [OrdersController::class, 'index']);
        $g->get('/account/ordini/{id:\d+}', [OrdersController::class, 'show']);
        $g->get('/account/ordini/{id:\d+}/proforma.pdf', [OrdersController::class, 'proforma']);

        $g->get('/account', [AccountController::class, 'show']);
        $g->post('/account', [AccountController::class, 'update']);
        $g->post('/account/password', [AccountController::class, 'changePassword']);
    })->add(RequireCustomerMiddleware::class);

    // --- Admin -------------------------------------------------------------------------------
    $app->get('/admin/login', [AdminAuthController::class, 'loginForm']);
    $app->post('/admin/login', [AdminAuthController::class, 'login']);
    $app->group('/admin', function (RouteCollectorProxy $g): void {
        $g->get('', [AdminDashboardController::class, 'index']);
        $g->post('/logout', [AdminAuthController::class, 'logout']);

        $g->get('/impostazioni', [AdminSettingsController::class, 'index']);
        $g->post('/impostazioni/{key}', [AdminSettingsController::class, 'update']);
        $g->post('/impostazioni/{key}/ripristina', [AdminSettingsController::class, 'reset']);

        $g->get('/ordini', [AdminOrdersController::class, 'index']);
        $g->get('/ordini/{id:\d+}', [AdminOrdersController::class, 'show']);
        $g->get('/ordini/{id:\d+}/proforma.pdf', [AdminOrdersController::class, 'proforma']);
        $g->post('/ordini/{id:\d+}/conferma', [AdminOrdersController::class, 'confirm']);
        $g->post('/ordini/{id:\d+}/annulla', [AdminOrdersController::class, 'cancel']);
        $g->post('/ordini/{id:\d+}/riallinea', [AdminOrdersController::class, 'realign']);
        $g->post('/ordini/{id:\d+}/fornitore', [AdminOrdersController::class, 'supplierCreate']);
        $g->post('/ordini/{id:\d+}/fornitore/rileggi', [AdminOrdersController::class, 'supplierRefresh']);
        $g->post('/ordini/{id:\d+}/spedito', [AdminOrdersController::class, 'markShipped']);

        $g->get('/ordini-fornitore', [AdminSupplierOrdersController::class, 'index']);
        $g->get('/ordini-fornitore/{id:\d+}', [AdminSupplierOrdersController::class, 'show']);

        $g->get('/webhook', [AdminWebhooksController::class, 'index']);
        $g->post('/webhook/simula', [AdminWebhooksController::class, 'simulate']);

        $g->get('/richieste-profilo', [AdminRequestsController::class, 'index']);
        $g->get('/richieste-profilo/{id:\d+}', [AdminRequestsController::class, 'show']);
        $g->post('/richieste-profilo/{id:\d+}/approva', [AdminRequestsController::class, 'approve']);
        $g->post('/richieste-profilo/{id:\d+}/rifiuta', [AdminRequestsController::class, 'reject']);

        $g->get('/prodotti', [AdminProductsController::class, 'index']);
        $g->get('/prodotti/{id:\d+}', [AdminProductsController::class, 'show']);
        $g->post('/prodotti/{id:\d+}/evidenza', [AdminProductsController::class, 'feature']);

        $g->get('/margini', [AdminMarginsController::class, 'index']);
        $g->post('/margini', [AdminMarginsController::class, 'store']);
        $g->post('/margini/{id:\d+}', [AdminMarginsController::class, 'update']);
        $g->post('/margini/{id:\d+}/stato', [AdminMarginsController::class, 'toggle']);
        $g->post('/margini/{id:\d+}/elimina', [AdminMarginsController::class, 'delete']);

        $g->get('/spedizione', [AdminShippingController::class, 'index']);
        $g->post('/spedizione', [AdminShippingController::class, 'create']);
        $g->post('/spedizione/paesi', [AdminShippingController::class, 'countries']);
        $g->post('/spedizione/{zone:[A-Z][A-Z0-9_]{1,11}}', [AdminShippingController::class, 'update']);
        $g->post('/spedizione/{zone:[A-Z][A-Z0-9_]{1,11}}/elimina', [AdminShippingController::class, 'delete']);

        $g->get('/sync', [AdminSyncController::class, 'index']);
        $g->post('/sync/{job:[a-z_]+}', [AdminSyncController::class, 'run']);

        $g->get('/clienti', [AdminCustomersController::class, 'index']);
        $g->get('/clienti/nuovo', [AdminCustomersController::class, 'create']);
        $g->get('/clienti/export.xlsx', [AdminCustomersController::class, 'export']);
        $g->post('/clienti', [AdminCustomersController::class, 'store']);
        $g->get('/clienti/{id:\d+}', [AdminCustomersController::class, 'show']);
        $g->post('/clienti/{id:\d+}', [AdminCustomersController::class, 'update']);
        $g->post('/clienti/{id:\d+}/invito', [AdminCustomersController::class, 'invite']);
        $g->post('/clienti/{id:\d+}/reset', [AdminCustomersController::class, 'reset']);
        $g->post('/clienti/{id:\d+}/stato', [AdminCustomersController::class, 'toggle']);
    })->add(RequireAdminMiddleware::class);
};
