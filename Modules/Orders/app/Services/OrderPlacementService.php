<?php

namespace Modules\Orders\Services;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\DisabledWarehouseGateway;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Orders\Exceptions\IdempotencyKeyConflictException;
use Modules\Orders\Exceptions\InsufficientStockException;
use Modules\Orders\Exceptions\OrderInProgressException;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Throwable;

/**
 * Places an order across two databases that share no transaction:
 *
 * 1. Record the intent locally, so a crash can always be cleaned up.
 * 2. Reserve all the stock in one Warehouse call.
 * 3. Save the order in a local transaction, or release the stock if that fails.
 * 4. Confirm the reservation. A failure here is retried later, because the order
 *    is valid and its stock is held.
 */
class OrderPlacementService
{
    public function __construct(
        private readonly WarehouseGateway $warehouse,
        private readonly OrderStockService $stock,
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    public function place(User $user, array $items, ?string $idempotencyKey = null): Order
    {
        // Until the integration is activated, refuse before recording anything.
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

        $this->reserve($reservation, $quantities);

        try {
            $order = $this->saveOrder($user, $reservation, $quantities);
        } catch (Throwable $e) {
            $this->releaseAfterFailedSave($reservation);

            throw $e;
        }

        $this->stock->confirm($reservation);

        $this->notifier->orderPlaced($order, $user->email);

        return $order;
    }

    /**
     * @return array{0: OrderReservation|null, 1: Order|null} A reservation to work
     *                                                        on, or the order an
     *                                                        earlier request made.
     */
    private function begin(User $user, array $quantities, ?string $idempotencyKey): array
    {
        $attributes = [
            'reservation_key' => (string) Str::uuid(),
            'user_id' => $user->id,
            'idempotency_key' => filled($idempotencyKey) ? hash('sha256', $idempotencyKey) : null,
            'request_hash' => hash('sha256', json_encode($quantities)),
            'items' => $quantities,
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

    /** Handles a repeated Idempotency-Key. */
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

        // The earlier attempt failed and holds no stock, so try again. A new key is
        // needed because the Warehouse permanently refuses a released one.
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

    private function reserve(OrderReservation $reservation, array $quantities): void
    {
        try {
            $result = $this->warehouse->reserve($reservation->reservation_key, $quantities);
        } catch (WarehouseException $e) {
            // A timeout doesn't mean it failed, so make sure no stock stays held.
            $this->stock->release($reservation);

            throw $e;
        }

        if ($result->success) {
            return;
        }

        if (in_array($result->code, [WarehouseResultCode::InsufficientStock, WarehouseResultCode::ProductNotFound], true)) {
            $reservation->update(['status' => OrderReservation::REJECTED]);

            throw $this->insufficientStock($result);
        }

        Log::error('Warehouse refused a new reservation', [
            'reservation_id' => $reservation->id,
            'code' => $result->code->name,
            'reason' => $result->reason,
        ]);
        $this->stock->release($reservation);

        throw new WarehouseRequestException();
    }

    private function insufficientStock(WarehouseResult $result): InsufficientStockException
    {
        $shortage = $result->shortage;
        $name = Product::query()->whereKey($shortage->productId)->value('name');

        return new InsufficientStockException(
            message: "Insufficient stock for product: {$name}",
            errors: [
                'product_id' => $shortage->productId,
                'available' => $shortage->available,
                'requested' => $shortage->requested,
            ]
        );
    }

    private function saveOrder(User $user, OrderReservation $reservation, array $quantities): Order
    {
        return DB::transaction(function () use ($user, $reservation, $quantities) {
            $order = Order::query()->create([
                'user_id' => $user->id,
                'status' => 'pending',
                'total_amount' => 0,
            ]);

            $total = 0;

            foreach ($quantities as $productId => $quantity) {
                $product = Product::query()->findOrFail($productId);

                // اعتماد السعر المخزن في قاعدة البيانات
                $price = $product->price;

                // إنشاء تفاصيل الطلب
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                ]);

                // تجميع إجمالي الطلب
                $total += $price * $quantity;
            }

            // تحديث إجمالي الطلب
            $order->update([
                'total_amount' => $total,
            ]);

            $reservation->update([
                'order_id' => $order->id,
                'status' => OrderReservation::SAVED,
            ]);

            return $order->load('items');
        });
    }

    /**
     * If recording the release fails too, for example because the database is down,
     * the record stays pending and reconcile() releases the stock later. The
     * caller's original error is the one worth reporting.
     */
    private function releaseAfterFailedSave(OrderReservation $reservation): void
    {
        try {
            // The failed transaction's changes to this model were rolled back in the
            // database, but not on the object.
            $reservation->refresh();
            $this->stock->release($reservation);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
