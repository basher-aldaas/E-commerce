<?php

namespace Modules\Orders\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class OrderService
{
    public function viewOrder(int $orderId, User $user): Order
    {
        $order = Order::query()->findOrFail($orderId);

        $isAdmin = $user->hasRole('admin');

        $isOwner = (int) $order->user_id === (int) $user->id;

        abort_unless(
            $isAdmin || $isOwner,
            403,
            'You are not allowed to view this order.'
        );

        return $order;
    }

    public function getOrders(): mixed
    {
        $user = auth('api')->user();

        $query = Order::query();

        // الأدمن يشوف جميع الطلبات
        if (!$user->hasRole('admin')) {
            // المستخدم العادي يشوف طلباته فقط
            $query->where('user_id', $user->id);
        }

        return $query->latest()->paginate(15);
    }

    public function updateStatus(int $orderId, string $status): Order
    {
        $user = auth('api')->user();

        abort_unless(
            $user->hasRole('admin'),
            403,
            'You are not allowed to update order status.'
        );

        $order = Order::query()->findOrFail($orderId);

        $order->update([
            'status' => $status,
        ]);

        return $order->refresh();
    }

    public function createOrder(array $data): Order
    {
        $user = auth('api')->user();

        return DB::transaction(function () use ($data, $user) {

            $order = Order::query()->create([
                'user_id' => $user->id,
                'status' => 'pending',
                'total_amount' => 0,
            ]);

            $total = 0;

            foreach ($data['items'] as $item) {

                // قفل سجل المنتج لمنع تعارض عمليات الشراء المتزامنة
                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($item['product_id']);

                $quantity = (int) $item['quantity'];

                // التحقق من توفر الكمية
                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Insufficient stock for product: {$product->name}",
                        ],
                    ]);
                }

                // اعتماد السعر المخزن في قاعدة البيانات
                $price = $product->price;
                $subtotal = $price * $quantity;

                // إنشاء تفاصيل الطلب
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                ]);

                // خصم الكمية من المخزون
                $product->decrement('stock', $quantity);

                // تجميع إجمالي الطلب
                $total += $subtotal;
            }

            // تحديث إجمالي الطلب
            $order->update([
                'total_amount' => $total,
            ]);

            return $order->load('items');
        });
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


}
