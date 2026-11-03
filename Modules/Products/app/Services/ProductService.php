<?php

namespace Modules\Products\Services;

use Illuminate\Http\UploadedFile;
use Modules\Products\Models\Product;

class ProductService
{
    public function getAll(): mixed
    {
        return Product::query()
            ->with('category')
            ->latest()
            ->paginate(15);
    }

    public function getById(int $productId): Product
    {
        return Product::query()
            ->with('category')
            ->findOrFail($productId);
    }

    /**
     * Products listing for API v2: active-only by default, with optional filters.
     *
     * Supported filter keys: search, category_id, min_price, max_price,
     * in_stock, per_page.
     */
    public function getAllForV2(array $filters): mixed
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return Product::query()
            ->with('category')
            ->where('is_active', true)
            ->when(
                $filters['search'] ?? null,
                fn ($query, $search) => $query->where('name', 'like', "%{$search}%")
            )
            ->when(
                $filters['category_id'] ?? null,
                fn ($query, $categoryId) => $query->where('category_id', $categoryId)
            )
            ->when(
                isset($filters['min_price']),
                fn ($query) => $query->where('price', '>=', $filters['min_price'])
            )
            ->when(
                isset($filters['max_price']),
                fn ($query) => $query->where('price', '<=', $filters['max_price'])
            )
            ->when(
                filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn ($query) => $query->where('stock', '>', 0)
            )
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $data): Product
    {
        // Persist an uploaded image to the public disk and store its path.
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $data['image'] = $data['image']->store('products', 'public');
        }

        // refresh() so DB defaults (e.g. is_active) are reflected in the response.
        return Product::create($data)->refresh();
    }
}
