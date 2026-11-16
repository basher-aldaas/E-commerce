<?php

namespace App\Services\Warehouse\Data;

final readonly class StockShortage
{
    /**
     * @param  bool  $tracked  False when the Warehouse has no stock row for the product.
     */
    public function __construct(
        public int $productId,
        public int $requested,
        public int $available,
        public bool $tracked = true,
    ) {}
}
