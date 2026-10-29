<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Products\Services\InventoryService;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    public function update(Request $request, int $product): JsonResponse
    {
        $validated = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
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
