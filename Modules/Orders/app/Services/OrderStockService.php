<?php

namespace Modules\Orders\Services;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;

/**
 * Gives reservation stock back through the Warehouse's Release operation, for
 * compensation and for cancellation. Release is idempotent on the Warehouse side,
 * so every step here can be repeated safely; anything interrupted is finished
 * later by reconcile().
 *
 * Several processes can work on the same reservation at once (a stalled order
 * request, its compensation, an admin's cancellation, two reconcile runs), so every
 * status change is conditional: it only applies if the row is still the same
 * attempt (reservation_key) in the state this step expects. A step that loses
 * that race changes nothing.
 *
 * Never call this inside a database transaction: a local rollback can't undo what
 * the Warehouse has done.
 */
class OrderStockService
{
    /** States from which a reservation may be released (it holds stock, has no order). */
    private const RELEASABLE = [OrderReservation::PENDING, OrderReservation::RELEASE_PENDING];

    public function __construct(
        private readonly WarehouseGateway $warehouse,
        private readonly OrderNotifier $notifier,
    ) {}

    /** The gateway with one HTTP request's time budget (warehouse.request_budget_ms). */
    public function budgeted(): WarehouseGateway
    {
        return $this->warehouse->withDeadline((int) config('warehouse.request_budget_ms'));
    }

    /**
     * Compensation: gives back stock for an order that won't exist. Never throws on
     * Warehouse failure. $warehouse lets the caller pass its time-budgeted gateway.
     */
    public function release(OrderReservation $reservation, ?WarehouseGateway $warehouse = null): void
    {
        try {
            $result = ($warehouse ?? $this->warehouse)->release($reservation->reservation_key);
        } catch (WarehouseException $e) {
            Log::warning('Release could not reach the Warehouse; left for warehouse:reconcile', [
                'reservation_id' => $reservation->id,
                'error' => $e::class,
            ]);
            $this->transition($reservation, self::RELEASABLE, OrderReservation::RELEASE_PENDING);

            return;
        }

        $this->settle($reservation, $result, self::RELEASABLE, OrderReservation::RELEASED, 'release');
    }

    /**
     * Releases the stock of an order being cancelled, marks the order cancelled and
     * notifies the customer. If another process finishes the same cancellation
     * first, this one returns the cancelled order without notifying again.
     *
     * @throws WarehouseException The order keeps its status and can be retried.
     */
    public function releaseAndCancel(OrderReservation $reservation, ?WarehouseGateway $warehouse = null): Order
    {
        $result = ($warehouse ?? $this->warehouse)->release($reservation->reservation_key);

        if (! $result->success) {
            $this->settle($reservation, $result, [OrderReservation::CANCEL_PENDING], OrderReservation::CANCELLED, 'release a cancelled order');

            throw new WarehouseRequestException();
        }

        [$order, $cancelledNow] = DB::transaction(function () use ($reservation) {
            // Same lock order as OrderService::updateStatus: the order row first.
            $order = Order::query()->lockForUpdate()->findOrFail($reservation->order_id);

            if (! $this->transition($reservation, [OrderReservation::CANCEL_PENDING], OrderReservation::CANCELLED)) {
                return [$order, false];
            }

            $order->update(['status' => 'cancelled']);

            return [$order->refresh(), true];
        });

        if (! $cancelledNow && $reservation->status !== OrderReservation::CANCELLED) {
            // Another process moved it elsewhere (for example to attention).
            throw new WarehouseRequestException();
        }

        if ($cancelledNow) {
            $this->notifier->statusChanged($order);
        }

        return $order;
    }

    /**
     * Finishes reservations left unsettled by a failure or crash.
     *
     * release_pending and cancel_pending are retried at once: no request is working
     * on them any more, and Release is idempotent. A pending record is only touched
     * once it is older than $olderThanMinutes, because until then its request may
     * still be running (it gives up within warehouse.request_budget_ms); an old one
     * belongs to a request that died between Reserve and saving the order, or one
     * that stalled there. Reconcile claims it (pending -> release_pending) before
     * calling Release, so a stalled request that wakes up can no longer place it.
     *
     * @return array{settled: int, failed: int, skipped: int}
     */
    public function reconcile(int $olderThanMinutes): array
    {
        $counts = ['settled' => 0, 'failed' => 0, 'skipped' => 0];
        $cutoff = now()->subMinutes($olderThanMinutes);

        OrderReservation::query()
            ->where(fn ($query) => $query
                ->whereIn('status', [OrderReservation::RELEASE_PENDING, OrderReservation::CANCEL_PENDING])
                ->orWhere(fn ($pending) => $pending
                    ->where('status', OrderReservation::PENDING)
                    ->where('updated_at', '<=', $cutoff)))
            ->orderBy('id')
            ->each(function (OrderReservation $reservation) use (&$counts, $cutoff) {
                $counts[$this->reconcileOne($reservation, $cutoff)]++;
            });

        return $counts;
    }

    /** @return 'settled'|'failed'|'skipped' */
    private function reconcileOne(OrderReservation $reservation, Carbon $cutoff): string
    {
        if ($reservation->status === OrderReservation::CANCEL_PENDING) {
            try {
                $this->releaseAndCancel($reservation);
            } catch (WarehouseException) {
                return 'failed';
            }

            return 'settled';
        }

        if ($reservation->status === OrderReservation::PENDING && ! $this->claimStalled($reservation, $cutoff)) {
            // Its request saved the order (placed) or gave up (released, rejected)
            // after this run read the row: nothing to release.
            return 'skipped';
        }

        $this->release($reservation);

        return $reservation->status === OrderReservation::RELEASE_PENDING ? 'failed' : 'settled';
    }

    /**
     * pending -> release_pending in one conditional UPDATE, only if the row is still
     * the same stalled attempt. The order request's own "pending -> placed" update
     * (OrderPlacementService::saveOrder) is conditional too, so exactly one wins.
     */
    private function claimStalled(OrderReservation $reservation, Carbon $cutoff): bool
    {
        $claimed = OrderReservation::query()
            ->whereKey($reservation->getKey())
            ->where('reservation_key', $reservation->reservation_key)
            ->where('status', OrderReservation::PENDING)
            ->where('updated_at', '<=', $cutoff)
            ->update(['status' => OrderReservation::RELEASE_PENDING]);

        $reservation->refresh();

        return $claimed === 1;
    }

    /**
     * Moves the reservation to $to only if it is still this attempt and in one of
     * $from. Returns false, changing nothing, if another process got there first.
     * The model is refreshed either way.
     *
     * @param  list<string>  $from
     */
    private function transition(OrderReservation $reservation, array $from, string $to): bool
    {
        $changed = OrderReservation::query()
            ->whereKey($reservation->getKey())
            ->where('reservation_key', $reservation->reservation_key)
            ->whereIn('status', $from)
            ->update(['status' => $to]);

        $reservation->refresh();

        return $changed === 1;
    }

    /** @param  list<string>  $from */
    private function settle(OrderReservation $reservation, WarehouseResult $result, array $from, string $status, string $step): void
    {
        if ($result->success) {
            $this->transition($reservation, $from, $status);

            return;
        }

        Log::error("Warehouse refused to {$step}", [
            'reservation_id' => $reservation->id,
            'order_id' => $reservation->order_id,
            'code' => $result->code->name,
            'reason' => $result->reason,
        ]);

        $this->transition($reservation, $from, OrderReservation::ATTENTION);
    }
}
