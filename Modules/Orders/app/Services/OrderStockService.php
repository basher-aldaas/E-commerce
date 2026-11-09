<?php

namespace Modules\Orders\Services;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;

/**
 * Moves a reservation through its life in the Warehouse. Every step can be
 * repeated safely, so anything interrupted is finished later by reconcile().
 *
 * Never call this inside a database transaction: a local rollback can't undo what
 * the Warehouse has done.
 */
class OrderStockService
{
    public function __construct(
        private readonly WarehouseGateway $warehouse,
        private readonly OrderNotifier $notifier,
    ) {}

    /** Gives back stock for an order that won't exist. Never throws on Warehouse failure. */
    public function release(OrderReservation $reservation): void
    {
        try {
            $result = $this->warehouse->release($reservation->reservation_key);
        } catch (WarehouseException) {
            $reservation->update(['status' => OrderReservation::RELEASE_PENDING]);

            return;
        }

        $this->settle($reservation, $result, OrderReservation::RELEASED, 'release');
    }

    /** Confirms a saved order's reservation. Never throws on Warehouse failure. */
    public function confirm(OrderReservation $reservation): void
    {
        try {
            $result = $this->warehouse->confirm($reservation->reservation_key);
        } catch (WarehouseException) {
            return; // Stays SAVED, and reconcile() confirms it later.
        }

        $this->settle($reservation, $result, OrderReservation::CONFIRMED, 'confirm');
    }

    /**
     * Returns the stock of an order being cancelled, then marks the order cancelled.
     *
     * @throws WarehouseException The order stays as it was and can be retried.
     */
    public function returnAndCancel(OrderReservation $reservation): Order
    {
        $result = $this->warehouse->returnStock($reservation->reservation_key);

        // The earlier confirmation may never have reached the Warehouse.
        if ($result->code === WarehouseResultCode::InvalidState) {
            $this->warehouse->confirm($reservation->reservation_key);
            $result = $this->warehouse->returnStock($reservation->reservation_key);
        }

        if (! $result->success) {
            $this->settle($reservation, $result, OrderReservation::RETURNED, 'return');

            throw new WarehouseRequestException();
        }

        return DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => OrderReservation::RETURNED]);

            $order = Order::query()->lockForUpdate()->findOrFail($reservation->order_id);
            $order->update(['status' => 'cancelled']);

            return $order->refresh();
        });
    }

    /**
     * Finishes reservations left unsettled by a failure or crash.
     *
     * @return array{settled: int, failed: int}
     */
    public function reconcile(int $olderThanMinutes): array
    {
        $counts = ['settled' => 0, 'failed' => 0];

        OrderReservation::query()
            ->whereIn('status', [
                OrderReservation::PENDING,
                OrderReservation::RELEASE_PENDING,
                OrderReservation::SAVED,
                OrderReservation::RETURN_PENDING,
            ])
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes))
            ->orderBy('id')
            ->each(function (OrderReservation $reservation) use (&$counts) {
                $counts[$this->reconcileOne($reservation) ? 'settled' : 'failed']++;
            });

        return $counts;
    }

    private function reconcileOne(OrderReservation $reservation): bool
    {
        if ($reservation->status === OrderReservation::RETURN_PENDING) {
            try {
                $this->notifier->statusChanged($this->returnAndCancel($reservation));
            } catch (WarehouseException) {
                return false;
            }

            return true;
        }

        if ($reservation->status === OrderReservation::SAVED) {
            $this->confirm($reservation);
        } else {
            $this->release($reservation);
        }

        return ! in_array($reservation->status, [
            OrderReservation::PENDING,
            OrderReservation::RELEASE_PENDING,
            OrderReservation::SAVED,
        ], true);
    }

    private function settle(OrderReservation $reservation, WarehouseResult $result, string $status, string $step): void
    {
        if ($result->success) {
            $reservation->update(['status' => $status]);

            return;
        }

        Log::error("Warehouse refused to {$step} a reservation", [
            'reservation_id' => $reservation->id,
            'order_id' => $reservation->order_id,
            'code' => $result->code->name,
            'reason' => $result->reason,
        ]);

        $reservation->update(['status' => OrderReservation::ATTENTION]);
    }
}
