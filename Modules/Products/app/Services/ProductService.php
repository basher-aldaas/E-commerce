<?php

namespace Modules\Products\Services;

use App\Exceptions\Warehouse\WarehouseException;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Products\Exceptions\ProductStockConflictException;
use Modules\Products\Models\Product;

class ProductService
{
    public function __construct(
        private readonly WarehouseGateway $warehouse,
        private readonly ProductStockReader $stockReader,
    ) {}

    public function getAll(): mixed
    {
        $products = Product::query()
            ->with('category')
            ->latest()
            ->paginate(15);

        $this->stockReader->attach($products->getCollection());

        return $products;
    }

    public function getById(int $productId): Product
    {
        $product = Product::query()
            ->with('category')
            ->findOrFail($productId);

        $this->stockReader->attach([$product]);

        return $product;
    }

    /**
     * Products listing for API v2: active-only by default, with optional filters.
     *
     * Supported filter keys: search, category_id, min_price, max_price,
     * in_stock, per_page.
     *
     * The in_stock filter needs the Warehouse's answer, so it fails with a
     * Warehouse error, rather than returning a wrong list, when that's unavailable.
     */
    public function getAllForV2(array $filters): mixed
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

        if (filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereIn('id', $this->idsInStock($query));
        }

        $products = $query->latest()->paginate($perPage);

        $this->stockReader->attach($products->getCollection());

        return $products;
    }

    public function create(array $data): Product
    {
        $quantity = (int) $data['stock'];
        unset($data['stock']);

        // Persist an uploaded image to the public disk and store its path.
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $data['image'] = $data['image']->store('products', 'public');
        }

        // refresh() so DB defaults (e.g. is_active) are reflected in the response.
        $product = Product::create($data)->refresh();

        try {
            $result = $this->warehouse->initializeStock($product->id, $quantity);
        } catch (WarehouseException $e) {
            $this->discard($product);

            throw $e;
        }

        // A retry after a lost reply finds the row its first attempt created, with
        // the same quantity. A different quantity belongs to something else.
        $alreadyCreated = $result->code === WarehouseResultCode::AlreadyInitialized
            && $result->quantity === $quantity;

        if (! $result->success && ! $alreadyCreated) {
            Log::error('Warehouse refused to initialize new product stock', [
                'product_id' => $product->id,
                'code' => $result->code->name,
                'reason' => $result->reason,
            ]);
            $this->discard($product);

            throw new ProductStockConflictException();
        }

        return $product->withStockLevel($result->quantity);
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

    /** A new product without stock in the Warehouse could never be sold, so remove it. */
    private function discard(Product $product): void
    {
        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();
    }
}
