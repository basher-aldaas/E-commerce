<?php

namespace Modules\Orders\Services;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Messaging\Outbox\Outbox;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\DisabledWarehouseGateway;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Orders\Exceptions\IdempotencyKeyConflictException;
use Modules\Orders\Exceptions\InsufficientStockException;
use Modules\Orders\Exceptions\OrderInProgressException;
use Modules\Orders\Messaging\OrderCreatedEvent;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use RuntimeException;
use Throwable;

class OrderPlacementService
{
    public function __construct(
        private readonly WarehouseGateway $warehouse,
        private readonly OrderStockService $stock,
        private readonly Outbox $outbox,
    ) {}


    public function place(User $user, array $items, ?string $idempotencyKey = null): Order
    {
        if ($this->warehouse instanceof DisabledWarehouseGateway) {
            throw new WarehouseUnavailableException();
        }

        $quantities = [];
        foreach ($items as $item) {
            $quantities[(int) $item['product_id']] = (int) $item['quantity'];
        }
        ksort($quantities);

        [$reservation, $existingOrder] = $this->begin($user, $quantities, $idempotencyKey);

        if ($existingOrder !== null) {
            return $existingOrder->load('items');
        }

        $warehouse = $this->warehouse->withDeadline((int) config('warehouse.request_budget_ms'));

        $this->reserve($warehouse, $reservation, $quantities);

        try {
            $order = $this->saveOrder($user, $reservation, $quantities);
        } catch (Throwable $e) {
            $this->releaseAfterFailedSave($warehouse, $reservation, $e);

            throw $e;
        }

        return $order;
    }

    //حجز الكمية بجدول الريزرفيشن والتعامل مع ال idempotence_key
    private function begin(User $user, array $quantities, ?string $idempotencyKey): array
    {
        $attributes = [
            'reservation_key' => (string) Str::uuid(),
            'user_id' => $user->id,
            'idempotency_key' => filled($idempotencyKey) ? hash('sha256', $idempotencyKey) : null,//اذا كان المفتاح موجود عملو هاش والا نول
            'request_hash' => hash('sha256', json_encode($quantities)),// حساب الهاش لمحتويات الطلب نفسه لمقارنته مع الهاش السابق
            'status' => OrderReservation::PENDING,
        ];

        if ($attributes['idempotency_key'] === null) {
            return [OrderReservation::create($attributes), null];
        }

        try {
            return [OrderReservation::create($attributes), null];
        } catch (UniqueConstraintViolationException) {
            return $this->resume($user, $attributes);
        }
    }

    //استكمال محاول انشاء طلب له idempotence key
    private function resume(User $user, array $attributes): array
    {
        $existing = OrderReservation::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $attributes['idempotency_key'])
            ->firstOrFail();

        if ($existing->request_hash !== $attributes['request_hash']) {
            throw new IdempotencyKeyConflictException();
        }

        if ($existing->order_id !== null) {
            return [null, $existing->order];
        }

        $claimed = OrderReservation::query()
            ->whereKey($existing->id)
            ->whereIn('status', [OrderReservation::REJECTED, OrderReservation::RELEASED])
            ->update([
                'reservation_key' => $attributes['reservation_key'],
                'status' => OrderReservation::PENDING,
            ]);

        if ($claimed === 0) {
            throw new OrderInProgressException();
        }

        return [$existing->refresh(), null];
    }

    private function reserve(WarehouseGateway $warehouse, OrderReservation $reservation, array $quantities): void
    {
        try {
            $result = $warehouse->reserve($reservation->reservation_key, $quantities);
        } catch (WarehouseException $e) {
            //المستودع لا يستجيب للحجز فما بيحجز وبرجع خطأ
            if ($e instanceof WarehouseUnavailableException && ! $e->requestSent) {
                Log::warning('Reserve not sent: the Warehouse is unreachable', ['reservation_id' => $reservation->id]);
                $reservation->update(['status' => OrderReservation::REJECTED]);

                throw $e;
            }

            // الطلب نرسل عالمستودع ولكن ما نعرف النتيجة بسجل خطأ وبيعمل ريليز
            Log::warning('Reserve failed; releasing the key so no stock stays held', [
                'reservation_id' => $reservation->id,
                'error' => $e::class,
            ]);
            $this->stock->release($reservation, $warehouse);

            throw $e;
        }

        if ($result->success) {
            return;
        }

        //الكمية غير كافية فيرجع خطأ
        if ($result->code === WarehouseResultCode::InsufficientStock) {
            $reservation->update(['status' => OrderReservation::REJECTED]);

            throw $this->insufficientStock($result);
        }

        //خطأ غير معروف
        Log::error('Warehouse refused a new reservation', [
            'reservation_id' => $reservation->id,
            'code' => $result->code->name,
            'reason' => $result->reason,
        ]);
        $this->stock->release($reservation, $warehouse);

        throw new WarehouseRequestException();
    }

    //الكمية غير متوفرة بجبلي الكميات يلي متوفرة مع الكمية يلي طلبتها
    private function insufficientStock(WarehouseResult $result): InsufficientStockException
    {
        $names = Product::query()
            ->whereIn('id', array_map(fn ($s) => $s->productId, $result->shortages))
            ->pluck('name', 'id');

        $items = array_map(fn ($s) => [
            'product_id' => $s->productId,
            'name' => $names[$s->productId] ?? null,
            'requested_quantity' => $s->requested,
            'available_quantity' => $s->available,
            'stocked' => $s->tracked,
        ], $result->shortages);

        return new InsufficientStockException(
            message: count($items) === 1
                ? 'Insufficient stock for the requested product.'
                : 'Insufficient stock for the requested products.',
            errors: ['items' => $items],
        );
    }

    //حفظ الطلب بعد حفظ الحجز
    private function saveOrder(User $user, OrderReservation $reservation, array $quantities): Order
    {
        return DB::transaction(function () use ($user, $reservation, $quantities) {
            $order = Order::query()->create([
                'user_id' => $user->id,
                'status' => 'pending',
                'total_amount' => 0,
            ]);

            // One query for every product, prices taken from the database.
            $products = Product::query()->whereIn('id', array_keys($quantities))->get()->keyBy('id');

            $total = 0;

            foreach ($quantities as $productId => $quantity) {
                $product = $products->get($productId) ?? throw (new ModelNotFoundException())->setModel(Product::class, [$productId]);
                // اعتماد السعر المخزن في قاعدة البيانات
                $price = $product->price;

                // إنشاء تفاصيل الطلب
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                ]);

                $total += $price * $quantity;
            }

            // تحديث إجمالي الطلب
            $order->update([
                'total_amount' => $total,
            ]);

            $placed = OrderReservation::query()
                ->whereKey($reservation->id)
                ->where('status', OrderReservation::PENDING)
                ->update([
                    'order_id' => $order->id,
                    'status' => OrderReservation::PLACED,
                ]);

            if ($placed === 0) {
                throw new RuntimeException('The reservation is no longer pending, so the order cannot be placed.');
            }

            $reservation->forceFill(['order_id' => $order->id, 'status' => OrderReservation::PLACED])->syncOriginal();

            $order->load('items');

            // Same transaction as the order: the event exists if and only if the
            // order was committed, so it can neither be lost nor describe an
            // order that was rolled back.
            $this->outbox->record(
                OrderCreatedEvent::TYPE,
                'order',
                $order->id,
                OrderCreatedEvent::data($order, $user, $products),
            );

            return $order;
        });
    }

    //تحرير الحجز بعد فشل حفظ الطلب
    private function releaseAfterFailedSave(WarehouseGateway $warehouse, OrderReservation $reservation, Throwable $cause): void
    {
        Log::error('Order could not be saved after a successful reservation; releasing it', [
            'reservation_id' => $reservation->id,
            'error' => $cause::class,
        ]);

        try {
            $reservation->refresh();
            $this->stock->release($reservation, $warehouse);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
