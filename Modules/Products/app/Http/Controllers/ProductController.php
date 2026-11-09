<?php

namespace Modules\Products\Http\Controllers;

use App\Constants\HttpStatusConstants;
use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Modules\Products\Http\Requests\StoreProductRequest;
use Modules\Products\Http\Resources\ProductResource;
use Modules\Products\Services\ProductService;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    #[OA\Get(
        path: '/api/v1/products',
        tags: ['Products'],
        summary: 'List products',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Products retrieved successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
                    new OA\Property(property: 'message', type: 'string', example: 'Products retrieved successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Forbidden (missing view_products)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function index(): JsonResponse
    {
        $products = $this->productService->getAll();

        return Response::Success(
            data: ProductResource::collection($products),
            message: 'Products retrieved successfully',
        );
    }

    #[OA\Get(
        path: '/api/v1/products/{product}',
        tags: ['Products'],
        summary: 'Get a single product',
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
                    new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
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

    #[OA\Post(
        path: '/api/v1/products',
        tags: ['Products'],
        summary: 'Create a product',
        description: 'Creates the product and initializes its stock in the Warehouse service. If the Warehouse cannot do that, the product is not created.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name', 'price', 'category_id', 'stock'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Wireless Mouse'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'price', type: 'number', format: 'float', minimum: 0, example: 50.00),
                        new OA\Property(property: 'category_id', type: 'integer', example: 1),
                        new OA\Property(property: 'stock', type: 'integer', minimum: 0, maximum: 2147483647, example: 20),
                        new OA\Property(property: 'image', type: 'string', format: 'binary', nullable: true, description: 'JPG/PNG/WebP, max 2 MB'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Product created successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
                    new OA\Property(property: 'message', type: 'string', example: 'Product created successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 201),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Forbidden (missing create_product)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 409, description: 'The Warehouse already has different stock for this product ID', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorEnvelope')),
            new OA\Response(response: 503, description: 'Warehouse service unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 504, description: 'Warehouse service timed out', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
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
}