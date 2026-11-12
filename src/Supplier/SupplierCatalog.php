<?php

declare(strict_types=1);

namespace App\Supplier;

use App\Supplier\Model\ShippingProfile;
use App\Supplier\Model\StockFeed;
use App\Supplier\Model\SupplierProduct;

/**
 * Catalogo del fornitore visto dal resto dell'app (che non sa che dietro c'è WTB).
 * Le implementazioni lanciano solo eccezioni di App\Supplier\Exception.
 */
interface SupplierCatalog
{
    public function shippingProfile(): ShippingProfile;

    /**
     * Tutto il catalogo (tutte le pagine), già validato. Le righe non valide sono scartate
     * e finiscono in warnings().
     *
     * @return list<SupplierProduct>
     */
    public function allProducts(): array;

    /**
     * @param list<string> $skus
     * @return list<SupplierProduct>
     */
    public function productsBySku(array $skus): array;

    public function stock(): StockFeed;

    /** Avvisi raccolti durante l'ultima lettura (righe scartate e perché). @return list<string> */
    public function warnings(): array;
}
