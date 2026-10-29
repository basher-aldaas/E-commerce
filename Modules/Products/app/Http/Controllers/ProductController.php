<?php

namespace Modules\Products\Http\Controllers;

use App\Constants\HttpStatusConstants;
use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Products\Http\Requests\StoreProductRequest;
use Modules\Products\Services\ProductService;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    public function index(): JsonResponse
    {
        $products = $this->productService->getAll();

        return Response::Success(
            data: $products,
            message: 'Products retrieved successfully',
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->validated()
        );

        return Response::Success(
            data: $product,
            message: 'Product created successfully',
            code: HttpStatusConstants::HTTP_201_CREATED
        );
    }
}
