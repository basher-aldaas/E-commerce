<?php

namespace Modules\Orders\Messaging;

use Illuminate\Support\Collection;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

final class OrderCreatedEvent
{
    public const TYPE = 'order.created';

    //معلومات الطلب وممنتجاته ومعلومات المستخدم
    public static function data(Order $order, User $user, Collection $products): array
    {
        return [
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
                'created_at' => $order->created_at?->toIso8601String(),
                'items' => $order->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'name' => $products->get($item->product_id)?->name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                ])->values()->all(),
            ],
            'customer' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ];
    }
}
