<?php

namespace Modules\Products\Services;

use App\Exceptions\Warehouse\WarehouseException;
use App\Services\Warehouse\WarehouseGateway;
use Modules\Products\Models\Product;

class ProductStockReader
{
    public function __construct(
        private readonly WarehouseGateway $warehouse
    ) {}

    /**
     * Loads stock for all the products in one call. If the Warehouse can't answer,
     * stock stays null so that product pages remain available. The gateway logs
     * the failure.
     *
     * @param  iterable<Product>  $products
     */
    public function attach(iterable $products): void
    {
        $products = collect($products);

        try {
            $levels = $this->warehouse->getStock($products->pluck('id')->unique()->values()->all());
        } catch (WarehouseException) {
            return;
        }

        foreach ($products as $product) {
            $product->withStockLevel(($levels[$product->id] ?? null)?->quantity);
        }
    }
}
