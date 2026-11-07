<?php

namespace App\Services\Warehouse\Data;

final readonly class StockLevel
{
    /**
     * @param  bool  $tracked  False when the Warehouse has no stock row for the product.
     */
    public function __construct(
        public int $productId,
        public int $quantity,
        public bool $tracked,
    ) {}
}
