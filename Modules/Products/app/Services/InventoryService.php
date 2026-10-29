<?php

namespace Modules\Products\Services;

use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;

class InventoryService
{
    public function updateStock(int $productId, int $quantity): Product
    {
        return DB::transaction(function () use ($productId, $quantity) {
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($productId);

            $product->stock = $quantity;
            $product->save();

            return $product->refresh();
        });
    }
}
