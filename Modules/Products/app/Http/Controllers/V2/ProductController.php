<?php

namespace Modules\Products\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Products\Http\Resources\V2\ProductResource;
use Modules\Products\Services\ProductService;

/**
 * Products API — version 2.
 *
 * v2 adds query-based filtering (search, category, price range, in-stock),
 * returns only active products by default, and uses the richer V2 resource.
 * v1 remains unchanged for existing clients.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = $this->productService->getAllForV2($filters, $request->integer('page', 1));

        return Response::Success(
            data: ProductResource::collection($products),
            message: 'Products retrieved successfully',
        );
    }

    public function show(int $product): JsonResponse
    {
        $product = $this->productService->getById($product);

        return Response::Success(
            data: new ProductResource($product),
            message: 'Product retrieved V2',
        );
    }
}
