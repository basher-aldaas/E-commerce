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

class OrderStockService
{
    private const RELEASABLE = [OrderReservation::PENDING, OrderReservation::RELEASE_PENDING];

    public function __construct(
        private readonly WarehouseGateway $warehouse,
        private readonly OrderNotifier $notifier,
    ) {}

    //تابع يتحق اذا تم الانتهاء من المدة المسوحة للاتصال بالمستنودع
    public function budgeted(): WarehouseGateway
    {
        return $this->warehouse->withDeadline((int) config('warehouse.request_budget_ms'));
    }

    //تابع تحرير الكمية يلي بالحجز
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

    //تابع تحرير الكمية يلي بالحجز  ويلغي الحجز
    public function releaseAndCancel(OrderReservation $reservation, ?WarehouseGateway $warehouse = null): Order
    {
        $result = ($warehouse ?? $this->warehouse)->release($reservation->reservation_key);

        if (! $result->success) {
            $this->settle($reservation, $result, [OrderReservation::CANCEL_PENDING], OrderReservation::CANCELLED, 'release a cancelled order');

            throw new WarehouseRequestException();
        }

        [$order, $cancelledNow] = DB::transaction(function () use ($reservation) {
            $order = Order::query()->lockForUpdate()->findOrFail($reservation->order_id);

            if (! $this->transition($reservation, [OrderReservation::CANCEL_PENDING], OrderReservation::CANCELLED)) {
                return [$order, false];
            }

            $order->update(['status' => 'cancelled']);

            return [$order->refresh(), true];
        });

        if (! $cancelledNow && $reservation->status !== OrderReservation::CANCELLED) {
            throw new WarehouseRequestException();
        }

        if ($cancelledNow) {
            $this->notifier->statusChanged($order);
        }

        return $order;
    }

    //بجيب عدد الحجز يلي تم تسويتها وعدد يلي فشلوا وعدد يلي تم تخطيها
    public function reconcile(int $olderThanMinutes): array
    {
        $counts = ['settled' => 0, 'failed' => 0, 'skipped' => 0];
        $cutoff = now()->subMinutes($olderThanMinutes);

        OrderReservation::query()
            ->where(fn ($query) => $query
                ->whereIn('status', [OrderReservation::RELEASE_PENDING, OrderReservation::CANCEL_PENDING])//جيب الحجوزات يلي بدي الغيها ولسا ما لتغت ويلي بدي رجعها ولسا ما رجعت
                ->orWhere(fn ($pending) => $pending //وكمان الحجوزات يلي بيندينغ بس الها وقت طويل
                    ->where('status', OrderReservation::PENDING)
                    ->where('updated_at', '<=', $cutoff)))
            ->orderBy('id')
            ->each(function (OrderReservation $reservation) use (&$counts, $cutoff) {
                $counts[$this->reconcileOne($reservation, $cutoff)]++;
            });

        return $counts;
    }

    //اذا كانت الحالاة ارجاع رجع الكميات للمستودع وال ا اذا كانت الحالة بيندغ وماصرلها زمان مأنشها عمللها سكيب
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
            return 'skipped';
        }

        $this->release($reservation);

        return $reservation->status === OrderReservation::RELEASE_PENDING ? 'failed' : 'settled';
    }

    //اذا كان الحجز قديم بما يكفي يتم عمل ريليز له
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

    //تغيير حالة الحجز للريزرفيشن وليس الطلب
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


   //تحديدي الحالة النهائية للحجز بناءا على نتيجة عملية المستودع
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
