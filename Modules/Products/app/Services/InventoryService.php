<?php

namespace Modules\Products\Services;

use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Support\Facades\Log;
use Modules\Products\Models\Product;

class InventoryService
{
    public function __construct(
        private readonly WarehouseGateway $warehouse
    ) {}

    public function updateStock(int $productId, int $quantity): Product
    {
        $product = Product::query()->findOrFail($productId);

        $result = $this->warehouse->setStock($productId, $quantity);

        if (! $result->success) {
            Log::error('Warehouse refused a stock update', [
                'product_id' => $productId,
                'code' => $result->code->name,
                'reason' => $result->reason,
            ]);

            throw new WarehouseRequestException();
        }

        return $product->withStockLevel($result->quantity);
    }
}
