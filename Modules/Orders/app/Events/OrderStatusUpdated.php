<?php

namespace Modules\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Modules\Orders\Models\Order;

/**
 * WebSocket (Pusher) message: an order's status changed.
 *
 * Sent to the order's owner (private-orders.{userId}) and to every admin
 * (private-admin.orders). Broadcast "now" (no queue worker), and only after the
 * database transaction committed, so the browser never sees a status that was
 * rolled back. Dispatched through OrderNotifier, which never lets a Pusher
 * failure fail the status change.
 */
class OrderStatusUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly string $status,
        public readonly ?string $updatedAt,
    ) {}

    public static function from(Order $order): self
    {
        return new self(
            orderId: $order->id,
            userId: $order->user_id,
            status: $order->status,
            updatedAt: $order->updated_at?->toIso8601String(),
        );
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders.'.$this->userId),
            new PrivateChannel('admin.orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    // البيانات التي ترسل الى المتصفح
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
            'status' => $this->status,
            'updated_at' => $this->updatedAt,
        ];
    }
}
