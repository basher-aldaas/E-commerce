<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Orders\Http\Requests\CreateOrderRequest;
use Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use Modules\Orders\Services\OrderService;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService
    )
    {
    }

    #[OA\Get(
        path: '/api/v1/orders/{orderId}',
        tags: ['Orders'],
        summary: 'Get a single order',
        description: 'Returns the order with its line items. A non-admin user may only view their own orders.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Order ID', schema: new OA\Schema(type: 'integer'), example: 9),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order retrieved successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
                    new OA\Property(property: 'message', type: 'string', example: 'Order retrieved successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'You are not allowed to view this order', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 404, description: 'Order not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function show(Request $request, int $orderId): JsonResponse
    {
        $user = $request->user('api');

        $order = $this->orderService->viewOrder(
            $orderId,
            $user
        );

        return Response::Success(
            data: $order,
            message: 'Order retrieved successfully',
        );
    }

    #[OA\Get(
        path: '/api/v1/orders',
        tags: ['Orders'],
        summary: 'List orders',
        description: 'Admins see all orders; regular users see only their own. Paginated (15 per page).',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Orders retrieved successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(
                        property: 'data',
                        description: 'Laravel length-aware paginator',
                        properties: [
                            new OA\Property(property: 'current_page', type: 'integer', example: 1),
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Order')),
                            new OA\Property(property: 'last_page', type: 'integer', example: 3),
                            new OA\Property(property: 'per_page', type: 'integer', example: 15),
                            new OA\Property(property: 'total', type: 'integer', example: 42),
                        ],
                        type: 'object',
                    ),
                    new OA\Property(property: 'message', type: 'string', example: 'Orders retrieved successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Forbidden (missing view_orders)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function index(): JsonResponse
    {
        $orders = $this->orderService->getOrders();

        return Response::Success(
            data: $orders,
            message: 'Orders retrieved successfully',
        );
    }

    #[OA\Post(
        path: '/api/v1/orders/{orderId}/status',
        tags: ['Orders'],
        summary: 'Update an order\'s status (admin only)',
        description: 'Cancelling returns the order\'s stock to the Warehouse service first; if that fails, the order keeps its status and the request can be retried. A cancelled order cannot be reopened.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Order ID', schema: new OA\Schema(type: 'integer'), example: 9),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'processing', 'completed', 'cancelled'], example: 'processing'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order status updated successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
                    new OA\Property(property: 'message', type: 'string', example: 'Order status updated successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'You are not allowed to update order status', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 404, description: 'Order not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 409, description: 'The order cannot move to this status', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorEnvelope')),
            new OA\Response(response: 503, description: 'Warehouse service unavailable while cancelling', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 504, description: 'Warehouse service timed out while cancelling', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function updateStatus(
        UpdateOrderStatusRequest $request,
        int                      $orderId
    ): JsonResponse
    {
        $order = $this->orderService->updateStatus(
            $orderId,
            $request->validated()['status']
        );

        return Response::Success(
            data: $order,
            message: 'Order status updated successfully',
        );
    }

    #[OA\Post(
        path: '/api/v1/orders',
        tags: ['Orders'],
        summary: 'Place a new order',
        description: 'Creates an order for the authenticated user. All items are reserved in the Warehouse service in one call, and no order is created if that fails. Prices come from the database, not the request.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'A unique value per order attempt. Repeating it returns the original order instead of ordering twice.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        minItems: 1,
                        items: new OA\Items(
                            required: ['product_id', 'quantity'],
                            properties: [
                                new OA\Property(property: 'product_id', type: 'integer', example: 1),
                                new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: 2147483647, example: 2),
                            ],
                            type: 'object',
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Order created successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
                    new OA\Property(property: 'message', type: 'string', example: 'Order created successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 201),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Forbidden (missing create_order)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 409, description: 'Insufficient stock, or an order with this Idempotency-Key is still in progress', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 422, description: 'Validation failed, or the Idempotency-Key was used with different items', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorEnvelope')),
            new OA\Response(response: 503, description: 'Warehouse service unavailable. No order was created.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 504, description: 'Warehouse service timed out. No order was created.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function store(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->createOrder(
            $request->validated(),
            $request->header('Idempotency-Key')
        );

        return Response::Success(
            data: $order,
            message: 'Order created successfully',
            code: 201
        );
    }

    #[OA\Get(
        path: '/api/v1/orders/{orderId}/track',
        tags: ['Orders'],
        summary: 'Track an order\'s status',
        description: 'Lightweight endpoint returning just the order id and current status. A non-admin user may only track their own orders.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Order ID', schema: new OA\Schema(type: 'integer'), example: 9),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Order status retrieved successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'order_id', type: 'integer', example: 9),
                            new OA\Property(property: 'status', type: 'string', example: 'processing'),
                        ],
                        type: 'object',
                    ),
                    new OA\Property(property: 'message', type: 'string', example: 'Order status retrieved successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'You are not allowed to track this order', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 404, description: 'Order not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function trackStatus(int $orderId): JsonResponse
    {
        $status = $this->orderService->trackOrderStatus($orderId);

        return Response::Success(
            data: $status,
            message: 'Order status retrieved successfully',
        );
    }
}
