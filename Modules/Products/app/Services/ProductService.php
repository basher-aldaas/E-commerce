<?php

namespace Modules\Products\Services;

use Modules\Products\Models\Product;

class ProductService
{
    public function getAll(): mixed
    {
        return Product::query()
            ->latest()
            ->paginate(15);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }
}
