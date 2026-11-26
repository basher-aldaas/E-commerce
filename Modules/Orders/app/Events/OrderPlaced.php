<?php

namespace Modules\Orders\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Modules\Orders\Models\Order;

/**
 * WebSocket (Pusher) message: a new order was placed. Only admins receive it
 * (private-admin.orders), so their order list and dashboard show it at once.
 *
 * The payload carries the same fields as a row of GET /api/v1/orders, so the
 * admin screen can draw the row without another request. Broadcast after the
 * order's transaction committed (see OrderPlacementService::place).
 */
class OrderPlaced implements ShouldBroadcastNow
{
    public function __construct(public readonly array $order) {}

    public static function from(Order $order): self
    {
        return new self([
            'id' => $order->id,
            'user_id' => $order->user_id,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'created_at' => $order->created_at?->toIso8601String(),
        ]);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.orders')];
    }

    public function broadcastAs(): string
    {
        return 'order.placed';
    }

    public function broadcastWith(): array
    {
        return ['order' => $this->order];
    }
}
