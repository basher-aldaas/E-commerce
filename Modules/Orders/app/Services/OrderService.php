<?php

namespace Modules\Orders\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Orders\Exceptions\InsufficientStockException;
use Modules\Orders\Exceptions\OrderNotFoundException;
use Modules\Orders\Exceptions\UnauthorizedOrderAccessException;
use Modules\Orders\Mail\OrderConfirmationMail;
use Modules\Orders\Mail\OrderStatusUpdatedMail;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class OrderService
{
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

        return $order->load('items.product');
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

        if (! $user->hasRole('admin')) {
            throw new UnauthorizedOrderAccessException(
                message: 'You are not allowed to update order status.'
            );
        }

        $order = DB::transaction(function () use ($orderId, $status) {
            // Lock the order row so two concurrent status updates can't race.
            $order = Order::query()
                ->lockForUpdate()
                ->find($orderId);

            if ($order === null) {
                throw new OrderNotFoundException();
            }

            $order->update([
                'status' => $status,
            ]);

            return $order->refresh();
        });

        // Notify the customer once the change is committed.
        $order->loadMissing('user');

        if ($order->user !== null) {
            Mail::to($order->user->email)->send(new OrderStatusUpdatedMail($order));
        }

        return $order;
    }

    public function createOrder(array $data): Order
    {
        $user = auth('api')->user();

        $order = DB::transaction(function () use ($data, $user) {

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
                    throw new InsufficientStockException(
                        message: "Insufficient stock for product: {$product->name}",
                        errors: [
                            'product_id' => $product->id,
                            'available' => $product->stock,
                            'requested' => $quantity,
                        ]
                    );
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

        // Send the confirmation only after the transaction has committed.
        Mail::to($user->email)->send(new OrderConfirmationMail($order));

        return $order;
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


}
