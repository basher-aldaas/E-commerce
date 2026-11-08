<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Products\Services\InventoryService;
use OpenApi\Attributes as OA;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    #[OA\Patch(
        path: '/api/v1/inventory/{product}',
        tags: ['Inventory'],
        summary: 'Update a product\'s stock level',
        description: 'Sets the available quantity in the Warehouse service, which owns all stock.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, description: 'Product ID', schema: new OA\Schema(type: 'integer'), example: 1),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stock'],
                properties: [
                    new OA\Property(property: 'stock', type: 'integer', minimum: 0, maximum: 2147483647, example: 50),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Inventory updated successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
                    new OA\Property(property: 'message', type: 'string', example: 'Inventory updated successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Forbidden (missing manage_inventory)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorEnvelope')),
            new OA\Response(response: 503, description: 'Warehouse service unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 504, description: 'Warehouse service timed out', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function update(Request $request, int $product): JsonResponse
    {
        $validated = $request->validate([
            'stock' => ['required', 'integer', 'min:0', 'max:'.WarehouseGateway::MAX_QUANTITY],
        ]);

        $updatedProduct = $this->inventoryService->updateStock(
            $product,
            $validated['stock']
        );

        return Response::Success(
            data: $updatedProduct,
            message: 'Inventory updated successfully',
            code: 200
        );
    }
}
