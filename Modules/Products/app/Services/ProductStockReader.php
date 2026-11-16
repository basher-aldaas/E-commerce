<?php

namespace Modules\Products\Services;

use App\Exceptions\Warehouse\WarehouseException;
use App\Services\Warehouse\WarehouseGateway;
use App\Support\ServerTiming;
use Modules\Products\Models\Product;

class ProductStockReader
{
    public function __construct(
        private readonly WarehouseGateway $warehouse,
        private readonly ServerTiming $timing,
    ) {}

    /**
     * Loads live stock for all the products in one GetStock call. Stock is never
     * cached or stored in Laravel. If the Warehouse can't answer, every product
     * reads as `unavailable` so product pages stay up; the gateway logs the failure.
     *
     * @param  iterable<Product>  $products
     */
    public function attach(iterable $products): void
    {
        $products = collect($products);

        if ($products->isEmpty()) {
            return;
        }

        try {
            $levels = $this->timing->measure(
                'stock',
                fn () => $this->warehouse->getStock($products->pluck('id')->unique()->values()->all())
            );
        } catch (WarehouseException) {
            $products->each(fn (Product $product) => $product->withStockLevel(null));

            return;
        }

        foreach ($products as $product) {
            $product->withStockLevel($levels[$product->id] ?? null);
        }
    }
}
