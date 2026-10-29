<?php

namespace Modules\Products\Services;

use Modules\Products\Models\Category;

class CategoryService
{
    public function getAll(): mixed
    {
        return Category::query()
            ->latest()
            ->paginate(15);
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }
}
