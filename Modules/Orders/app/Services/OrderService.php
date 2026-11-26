<?php

namespace Modules\Orders\Services;

use Illuminate\Support\Facades\DB;
use Modules\Orders\Exceptions\OrderNotFoundException;
use Modules\Orders\Exceptions\OrderStatusConflictException;
use Modules\Orders\Exceptions\UnauthorizedOrderAccessException;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Services\ProductStockReader;
use Modules\Users\Models\User;

class OrderService
{
    public function __construct(
        private readonly OrderPlacementService $placement,
        private readonly OrderStockService $stock,
        private readonly OrderNotifier $notifier,
        private readonly ProductStockReader $stockReader,
    ) {}


    public function viewOrder(int $orderId, User $user): Order
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            throw new OrderNotFoundException();
        }

        $isAdmin = $user->hasRole('admin');

        $isOwner = (int) $order->user_id === (int) $user->id;

        if (! ($isAdmin || $isOwner)) {
            throw new UnauthorizedOrderAccessException(
                message: 'You are not allowed to view this order.'
            );
        }

        $order->load('items.product');

        $this->stockReader->attach($order->items->pluck('product')->filter());

        return $order;
    }

    public function getOrders(): mixed
    {
        $user = auth('api')->user();

        $query = Order::query();

        if (!$user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        }

        return $query->latest()->orderByDesc('id')->paginate(15);
    }

    public function updateStatus(int $orderId, string $status): Order
    {
        $user = auth('api')->user();

        if (! $user->hasRole('admin')) {
            throw new UnauthorizedOrderAccessException(
                message: 'You are not allowed to update order status.'
            );
        }

        [$order, $reservationToRelease] = DB::transaction(function () use ($orderId, $status) {
            // Lock the order row so two concurrent status updates can't race.
            $order = Order::query()
                ->lockForUpdate()
                ->find($orderId);

            if ($order === null) {
                throw new OrderNotFoundException();
            }

            $reservation = $order->reservation()->first();

            $this->guardStatusChange($order, $reservation, $status);

            if ($status === 'cancelled' && $this->holdsStock($reservation)) {
                $reservation->update(['status' => OrderReservation::CANCEL_PENDING]);

                return [$order, $reservation];
            }

            $order->update([
                'status' => $status,
            ]);

            return [$order->refresh(), null];
        });

        if ($reservationToRelease !== null) {
            // releaseAndCancel() notifies (email + WebSocket) once the order is cancelled.
            return $this->stock->releaseAndCancel($reservationToRelease, $this->stock->budgeted());
        }

        // After the commit: email + WebSocket (OrderStatusUpdated) to the customer and admins.
        $this->notifier->statusChanged($order);

        return $order;
    }

    public function createOrder(array $data, ?string $idempotencyKey = null): Order
    {
        return $this->placement->place(auth('api')->user(), $data['items'], $idempotencyKey);
    }

    public function trackOrderStatus(int $orderId): array
    {
        $user = auth('api')->user();

        $order = Order::query()->find($orderId);

        if ($order === null) {
            throw new OrderNotFoundException();
        }

        $isAdmin = $user->hasRole('admin');

        $isOwner = (int) $order->user_id === (int) $user->id;

        if (! ($isAdmin || $isOwner)) {
            throw new UnauthorizedOrderAccessException(
                message: 'You are not allowed to track this order.'
            );
        }

        return [
            'order_id' => $order->id,
            'status' => $order->status,
        ];
    }

    private function guardStatusChange(Order $order, ?OrderReservation $reservation, string $status): void
    {
        if ($status === 'cancelled') {
            return;
        }

        if ($order->status === 'cancelled') {
            throw new OrderStatusConflictException(
                message: 'A cancelled order cannot be reopened.'
            );
        }

        if ($reservation?->status === OrderReservation::CANCEL_PENDING) {
            throw new OrderStatusConflictException(
                message: 'This order is being cancelled. Retry the cancellation instead.'
            );
        }
    }

    private function holdsStock(?OrderReservation $reservation): bool
    {
        return in_array($reservation?->status, [
            OrderReservation::PLACED,
            OrderReservation::CANCEL_PENDING,
        ], true);
    }
}
