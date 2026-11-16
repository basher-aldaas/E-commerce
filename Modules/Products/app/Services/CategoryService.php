<?php

namespace Modules\Products\Services;

use Modules\Products\Models\Category;

class CategoryService
{
    public function __construct(
        private readonly CatalogCache $cache,
    ) {}

    public function getAll(int $page = 1): mixed
    {
        $page = max(1, $page);

        return $this->cache->remember(
            "categories:page:{$page}",
            fn () => Category::query()
                ->latest()
                ->orderByDesc('id') // stable order across pages for same-second rows
                ->paginate(15, ['*'], 'page', $page)
        );
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }
}
