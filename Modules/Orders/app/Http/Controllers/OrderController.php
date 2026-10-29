<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Orders\Http\Requests\CreateOrderRequest;
use Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use Modules\Orders\Services\OrderService;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService
    )
    {
    }

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

    public function index(): JsonResponse
    {
        $orders = $this->orderService->getOrders();

        return Response::Success(
            data: $orders,
            message: 'Orders retrieved successfully',
        );
    }

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

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->createOrder(
            $request->validated()
        );

        return Response::Success(
            data: $order,
            message: 'Order created successfully',
            code: 201
        );
    }

    public function trackOrderStatus(int $orderId): array
    {
        $user = auth('api')->user();

        $order = Order::query()->findOrFail($orderId);

        $isAdmin = $user->hasRole('admin');

        $isOwner = (int) $order->user_id === (int) $user->id;

        abort_unless(
            $isAdmin || $isOwner,
            403,
            'You are not allowed to track this order.'
        );

        return [
            'order_id' => $order->id,
            'status' => $order->status,
        ];
    }

    public function trackStatus(int $orderId): JsonResponse
    {
        $status = $this->orderService->trackOrderStatus($orderId);

        return Response::Success(
            data: $status,
            message: 'Order status retrieved successfully',
        );
    }
}
