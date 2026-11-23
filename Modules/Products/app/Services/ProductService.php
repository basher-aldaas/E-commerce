<?php

namespace Modules\Products\Services;

use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Modules\Products\Models\Product;

class ProductService
{
    public function __construct(
        private readonly WarehouseGateway $warehouse,
        private readonly ProductStockReader $stockReader,
        private readonly CatalogCache $cache,
    ) {}


    public function getAll(array $filters = [], int $page = 1): mixed
    {
        $page = max(1, $page);
        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 15;

        $products = $this->cache->remember(
            "v1:products:category:{$categoryId}:per_page:{$perPage}:page:{$page}",
            fn () => Product::query()
                ->with('category')
                ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
                ->latest()
                ->orderByDesc('id') // stable order across pages for same-second rows
                ->paginate($perPage, ['*'], 'page', $page)
        );

        $this->stockReader->attach($products->getCollection());

        return $products;
    }

    public function getById(int $productId): Product
    {
        $product = $this->cache->remember(
            "product:{$productId}",
            fn () => Product::query()
                ->with('category')
                ->findOrFail($productId)
        );

        $this->stockReader->attach([$product]);

        return $product;
    }

    public function getAllForV2(array $filters, int $page = 1): mixed
    {
        $page = max(1, $page);

        //اذا طلب المنتجات يلي فيها الكمية يلي عم يجيبها من المخزون فهون لا تخزن بالكاش لانو المخزون دايما عم يتغير
        if (filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $products = $this->cache->skip(fn () => $this->queryV2($filters, $page, inStockOnly: true));
        } else {
            $key = collect($filters)->except('in_stock')->filter(fn ($v) => $v !== null && $v !== '')->sortKeys()->all();

            $products = $this->cache->remember(
                'v2:products:'.hash('sha256', json_encode([$key, $page])),//لانو الفلاتر ممكن تكون طويلة فعمللها هاش من شان يخزنها بالكاش
                fn () => $this->queryV2($filters, $page, inStockOnly: false)
            );
        }

        $this->stockReader->attach($products->getCollection());

        return $products;
    }

    //تنفيذ الفلاتر
    private function queryV2(array $filters, int $page, bool $inStockOnly): mixed
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Product::query()
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
            );

        if ($inStockOnly) {
            $query->whereIn('id', $this->idsInStock($query));
        }

        return $query->latest()->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }


    public function create(array $data): Product
    {
        // Persist an uploaded image to the public disk and store its path.
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $data['image'] = $data['image']->store('products', 'public');
        }

        // refresh() so DB defaults (e.g. is_active) are reflected in the response.
        $product = Product::create($data)->refresh()->load('category');

        $this->stockReader->attach([$product]);

        return $product;
    }

    /**
     * Replaces the product's editable fields. Saving the model flushes the catalog
     * cache (Product::booted), so every list and detail page shows the change on
     * the next request. An image is only replaced when a new one is sent; the old
     * file stays on the disk.
     */
    public function update(int $productId, array $data): Product
    {
        $product = Product::query()->findOrFail($productId);

        if (($data['image'] ?? null) instanceof UploadedFile) {
            $data['image'] = $data['image']->store('products', 'public');
        } else {
            unset($data['image']);
        }

        $product->update($data);
        $product->refresh()->load('category');

        $this->stockReader->attach([$product]);

        return $product;
    }

    /** @return list<int> */
    private function idsInStock(Builder $query): array
    {
        $levels = $this->warehouse->getStock((clone $query)->pluck('id')->all());

        return collect($levels)
            ->filter(fn ($level) => $level->quantity > 0)
            ->keys()
            ->all();
    }
}
