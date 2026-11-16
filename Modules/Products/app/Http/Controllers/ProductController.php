<?php

namespace Modules\Products\Http\Controllers;

use App\Constants\HttpStatusConstants;
use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Products\Http\Requests\StoreProductRequest;
use Modules\Products\Http\Requests\UpdateProductRequest;
use Modules\Products\Http\Resources\ProductResource;
use Modules\Products\Services\ProductService;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $products = $this->productService->getAll($filters, $request->integer('page', 1));

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
            message: 'Product retrieved V1',
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->validated()
        );

        return Response::Success(
            data: new ProductResource($product),
            message: 'Product created successfully',
            code: HttpStatusConstants::HTTP_201_CREATED
        );
    }

    public function update(UpdateProductRequest $request, int $product): JsonResponse
    {
        $product = $this->productService->update($product, $request->validated());

        return Response::Success(
            data: new ProductResource($product),
            message: 'Product updated successfully',
        );
    }
}
