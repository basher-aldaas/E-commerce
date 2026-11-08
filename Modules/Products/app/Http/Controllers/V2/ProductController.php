<?php

namespace Modules\Products\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Products\Http\Resources\V2\ProductResource;
use Modules\Products\Services\ProductService;
use OpenApi\Attributes as OA;

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

    #[OA\Get(
        path: '/api/v2/products',
        tags: ['Products v2'],
        summary: 'List products with filtering (v2)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Full-text search on name', schema: new OA\Schema(type: 'string', maxLength: 255)),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'min_price', in: 'query', required: false, schema: new OA\Schema(type: 'number', format: 'float', minimum: 0)),
            new OA\Parameter(name: 'max_price', in: 'query', required: false, schema: new OA\Schema(type: 'number', format: 'float', minimum: 0)),
            new OA\Parameter(name: 'in_stock', in: 'query', required: false, description: 'Needs the Warehouse service: returns 503 or 504 if it cannot answer.', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Products retrieved successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductV2')),
                    new OA\Property(property: 'message', type: 'string', example: 'Products retrieved successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorEnvelope')),
            new OA\Response(response: 503, description: 'Warehouse service unavailable (only when using in_stock)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 504, description: 'Warehouse service timed out (only when using in_stock)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
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

        $products = $this->productService->getAllForV2($filters);

        return Response::Success(
            data: ProductResource::collection($products),
            message: 'Products retrieved successfully',
        );
    }

    #[OA\Get(
        path: '/api/v2/products/{product}',
        tags: ['Products v2'],
        summary: 'Get a single product (v2)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, description: 'Product ID', schema: new OA\Schema(type: 'integer'), example: 1),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Product retrieved successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', ref: '#/components/schemas/ProductV2'),
                    new OA\Property(property: 'message', type: 'string', example: 'Product retrieved successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function show(int $product): JsonResponse
    {
        $product = $this->productService->getById($product);

        return Response::Success(
            data: new ProductResource($product),
            message: 'Product retrieved successfully',
        );
    }
}