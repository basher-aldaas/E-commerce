<?php

namespace Modules\Orders\Services;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
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
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use RuntimeException;
use Throwable;

/**
 * Places an order across two databases that share no transaction. This is a
 * workflow with a compensating action, not a distributed transaction:
 *
 * 1. Record the intent locally (pending), so a crash can always be cleaned up.
 * 2. Reserve all the stock in one Warehouse call. The quantities sent are exactly
 *    the ones the customer asked for.
 * 3. Save the order and mark the reservation placed in one local transaction.
 * 4. If that save fails, Release the reservation (compensation). If the release
 *    can't reach the Warehouse either, the record stays release_pending and
 *    `warehouse:reconcile` finishes it.
 *
 * All Warehouse calls of one placement share one time budget
 * (warehouse.request_budget_ms), so the request can never stack timeouts.
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

        $warehouse = $this->warehouse->withDeadline((int) config('warehouse.request_budget_ms'));

        $this->reserve($warehouse, $reservation, $quantities);

        try {
            $order = $this->saveOrder($user, $reservation, $quantities);
        } catch (Throwable $e) {
            $this->releaseAfterFailedSave($warehouse, $reservation, $e);

            throw $e;
        }

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

    private function reserve(WarehouseGateway $warehouse, OrderReservation $reservation, array $quantities): void
    {
        try {
            $result = $warehouse->reserve($reservation->reservation_key, $quantities);
        } catch (WarehouseException $e) {
            if ($e instanceof WarehouseUnavailableException && ! $e->requestSent) {
                // No connection: the Reserve never left Laravel, so the Warehouse
                // holds nothing for this key and there is nothing to release. The
                // customer can retry at once, even with the same Idempotency-Key.
                Log::warning('Reserve not sent: the Warehouse is unreachable', ['reservation_id' => $reservation->id]);
                $reservation->update(['status' => OrderReservation::REJECTED]);

                throw $e;
            }

            // Sent but unanswered (timeout) or failed mid-call: the Reserve may still
            // land, so release the key; a late Reserve is then refused.
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

        if ($result->code === WarehouseResultCode::InsufficientStock) {
            $reservation->update(['status' => OrderReservation::REJECTED]);

            throw $this->insufficientStock($result);
        }

        Log::error('Warehouse refused a new reservation', [
            'reservation_id' => $reservation->id,
            'code' => $result->code->name,
            'reason' => $result->reason,
        ]);
        $this->stock->release($reservation, $warehouse);

        throw new WarehouseRequestException();
    }

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
                $product = $products->get($productId) ?? throw (new ModelNotFoundException())
                    ->setModel(Product::class, [$productId]);

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

            // Only a reservation that is still pending can become placed. If this
            // request stalled long enough for warehouse:reconcile to release it, the
            // stock is already back in the Warehouse: fail here, so the transaction
            // rolls back and no order points at stock that nothing holds.
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

            return $order->load('items');
        });
    }

    /**
     * Compensation. If recording the release fails too, for example because the
     * database is down, the record stays pending and reconcile() releases the stock
     * later. The caller's original error is the one worth reporting.
     */
    private function releaseAfterFailedSave(WarehouseGateway $warehouse, OrderReservation $reservation, Throwable $cause): void
    {
        Log::error('Order could not be saved after a successful reservation; releasing it', [
            'reservation_id' => $reservation->id,
            'error' => $cause::class,
        ]);

        try {
            // The failed transaction's changes to this model were rolled back in the
            // database, but not on the object.
            $reservation->refresh();
            $this->stock->release($reservation, $warehouse);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
