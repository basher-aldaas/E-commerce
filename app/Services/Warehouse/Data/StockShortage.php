<?php

namespace App\Services\Warehouse\Data;

final readonly class StockShortage
{
    public function __construct(
        public int $productId,
        public int $requested,
        public int $available,
    ) {}
}
