<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Orders\Mail\OrderStatusUpdatedMail;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Orders\Services\OrderPlacementService;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Tests\Feature\Requirement1\ApiTestCase;

class ReconcileReservationsTest extends ApiTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['warehouse.enabled' => true]);
        $this->product = $this->makeProduct(['stock' => 10]);
    }

    private function placeOrder(): int
    {
        $this->actingAsApi($this->makeUser());

        return (int) $this->postOrder([
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->json('data.id');
    }

    private function reconcile(int $olderThan = 0): string
    {
        $this->assertSame(0, Artisan::call('warehouse:reconcile', ['--older-than' => $olderThan]));

        return Artisan::output();
    }

    private function stock(): int
    {
        return $this->warehouse->stock[$this->product->id];
    }

    public function test_an_unacknowledged_release_is_finished(): void
    {
        $this->warehouse->loseNextReply('reserve', new WarehouseTimeoutException());
        $this->warehouse->failNext('release', new WarehouseUnavailableException());
        $this->placeOrder();
        $this->assertSame(8, $this->stock());

        $this->reconcile();

        $this->assertSame(OrderReservation::RELEASED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_a_retried_key_works_after_its_release_is_finished(): void
    {
        $user = $this->makeUser();
        $this->warehouse->loseNextReply('reserve', new WarehouseTimeoutException());
        $this->warehouse->failNext('release', new WarehouseUnavailableException());
        $payload = ['items' => [['product_id' => $this->product->id, 'quantity' => 2]]];

        $this->actingAsApi($user);
        $this->postOrder($payload, ['Idempotency-Key' => 'cart-1'])->assertStatus(504);
        $this->actingAsApi($user);
        $this->postOrder($payload, ['Idempotency-Key' => 'cart-1'])->assertStatus(409);

        $this->reconcile();

        $this->actingAsApi($user);
        $this->postOrder($payload, ['Idempotency-Key' => 'cart-1'])->assertStatus(201);
        $this->assertSame(8, $this->stock());
    }

    public function test_a_crash_after_reserving_is_released(): void
    {
        $user = $this->makeUser();
        $key = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $this->warehouse->reserve($key, [$this->product->id => 2]);
        OrderReservation::create([
            'reservation_key' => $key,
            'user_id' => $user->id,
            'request_hash' => 'x',
            'status' => OrderReservation::PENDING,
        ]);
        $this->assertSame(8, $this->stock());

        $this->reconcile();

        $this->assertSame(OrderReservation::RELEASED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_recent_reservations_are_left_alone(): void
    {
        OrderReservation::create([
            'reservation_key' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'user_id' => $this->makeUser()->id,
            'request_hash' => 'x',
            'status' => OrderReservation::PENDING,
        ]);

        $this->reconcile(olderThan: 2);

        $this->assertSame(OrderReservation::PENDING, OrderReservation::sole()->status);
        $this->assertSame([], $this->warehouse->callsTo('release'));
    }

    public function test_a_compensation_that_could_not_reach_the_warehouse_is_finished(): void
    {
        Event::listen('eloquent.creating: '.Order::class, function () {
            throw new RuntimeException('database failure');
        });
        $this->warehouse->failNext('release', new WarehouseUnavailableException());
        $this->placeOrder();
        Event::forget('eloquent.creating: '.Order::class);
        $this->assertSame(OrderReservation::RELEASE_PENDING, OrderReservation::sole()->status);
        $this->assertSame(8, $this->stock());

        $this->reconcile();

        $this->assertSame(OrderReservation::RELEASED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->stock());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_placed_order_is_never_released_by_reconcile(): void
    {
        $orderId = $this->placeOrder();

        $this->reconcile();

        $this->assertSame(OrderReservation::PLACED, OrderReservation::sole()->status);
        $this->assertSame([], $this->warehouse->callsTo('release'));
        $this->assertNotNull(Order::find($orderId));
        $this->assertSame(8, $this->stock());
    }

    public function test_an_unfinished_cancellation_is_completed_and_the_customer_notified(): void
    {
        $orderId = $this->placeOrder();
        $this->warehouse->failNext('release', new WarehouseUnavailableException());
        $this->actingAsApi($this->makeUser('admin'));
        $this->postJson("/api/v1/orders/{$orderId}/status", ['status' => 'cancelled'])->assertStatus(503);

        $this->reconcile();

        $this->assertSame('cancelled', Order::find($orderId)->status);
        $this->assertSame(OrderReservation::CANCELLED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->stock());
        Mail::assertQueued(OrderStatusUpdatedMail::class);
    }

    public function test_settled_reservations_are_not_touched(): void
    {
        $this->placeOrder();
        $callsBefore = count($this->warehouse->calls);

        $this->reconcile();

        $this->assertCount($callsBefore, $this->warehouse->calls);
    }

    public function test_a_warehouse_still_down_leaves_work_for_next_time(): void
    {
        $this->warehouse->loseNextReply('reserve', new WarehouseTimeoutException());
        $this->warehouse->failNext('release', new WarehouseUnavailableException(), times: 2);
        $this->placeOrder();

        $output = $this->reconcile();

        $this->assertStringContainsString('still unsettled 1', $output);
        $this->assertSame(OrderReservation::RELEASE_PENDING, OrderReservation::sole()->status);
        $this->assertSame(8, $this->stock());

        $this->reconcile();

        $this->assertSame(OrderReservation::RELEASED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_nothing_happens_while_the_integration_is_disabled(): void
    {
        $this->warehouse->loseNextReply('reserve', new WarehouseTimeoutException());
        $this->warehouse->failNext('release', new WarehouseUnavailableException());
        $this->placeOrder();
        config(['warehouse.enabled' => false]);
        $callsBefore = count($this->warehouse->calls);

        $this->reconcile();

        $this->assertCount($callsBefore, $this->warehouse->calls);
    }

    /**
     * A reservation whose order request stalled after a successful Reserve: still
     * pending, old enough for reconcile, its 2 units held by the Warehouse.
     */
    private function stalledReservation(User $user): OrderReservation
    {
        $key = (string) Str::uuid();
        $this->warehouse->reserve($key, [$this->product->id => 2]);
        $this->warehouse->calls = [];

        return OrderReservation::create([
            'reservation_key' => $key,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', json_encode([$this->product->id => 2])),
            'status' => OrderReservation::PENDING,
        ]);
    }

    /**
     * The stalled request wakes up and runs its last steps exactly as
     * OrderPlacementService::place() does after Reserve: save the order, and if that
     * fails, release the reservation (compensation). Runs the real private methods
     * on the request's own copy of the reservation.
     *
     * @return Throwable|null Why the save failed, or null if the order was saved.
     */
    private function stalledRequestSavesItsOrder(int $reservationId, User $user): ?Throwable
    {
        $placement = $this->app->make(OrderPlacementService::class);
        $reservation = OrderReservation::findOrFail($reservationId);

        try {
            (new ReflectionMethod($placement, 'saveOrder'))->invoke($placement, $user, $reservation, [$this->product->id => 2]);

            return null;
        } catch (Throwable $e) {
            (new ReflectionMethod($placement, 'releaseAfterFailedSave'))->invoke($placement, $this->warehouse, $reservation, $e);

            return $e;
        }
    }

    /**
     * Interleaving 1: reconcile has read the reservation as pending, then the stalled
     * request saves its order, then reconcile acts on what it read.
     */
    public function test_an_order_saved_right_after_reconcile_reads_the_reservation_keeps_its_stock(): void
    {
        $user = $this->makeUser();
        $reservation = $this->stalledReservation($user);
        $outcome = 'not reached';

        DB::listen(function ($query) use (&$outcome, $user, $reservation) {
            if ($outcome === 'not reached' && str_starts_with($query->sql, 'select * from `order_reservations` where (`status` in')) {
                $outcome = 'running';
                $outcome = $this->stalledRequestSavesItsOrder($reservation->id, $user) ?? 'saved';
            }
        });

        $this->reconcile();

        $this->assertSame('saved', $outcome);
        $order = Order::sole();
        $reservation->refresh();
        $this->assertSame(OrderReservation::PLACED, $reservation->status);
        $this->assertSame($order->id, $reservation->order_id);
        $this->assertSame('RESERVED', $this->warehouse->reservations[$reservation->reservation_key]['status']);
        $this->assertSame(8, $this->stock(), 'the stock of a placed order was given back');
        $this->assertSame([], $this->warehouse->callsTo('release'));
    }

    /**
     * Interleaving 2: reconcile is releasing the reservation when the stalled
     * request tries to save its order. The save must be refused and the stock
     * returned exactly once.
     */
    public function test_an_order_saved_while_reconcile_releases_the_reservation_is_refused(): void
    {
        $user = $this->makeUser();
        $reservation = $this->stalledReservation($user);
        $outcome = 'not reached';

        $this->warehouse->afterNext('release', function () use (&$outcome, $user, $reservation) {
            $outcome = $this->stalledRequestSavesItsOrder($reservation->id, $user);
        });

        $this->reconcile();

        $this->assertInstanceOf(RuntimeException::class, $outcome, 'the stalled request saved an order whose stock had been released');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(OrderReservation::RELEASED, $reservation->refresh()->status);
        $this->assertNull($reservation->order_id);
        $this->assertSame(10, $this->stock());
    }

    public function test_overlapping_reconcile_runs_release_a_stalled_reservation_once(): void
    {
        $reservation = $this->stalledReservation($this->makeUser());
        $this->warehouse->afterNext('release', fn () => $this->reconcile());

        $this->reconcile();

        $this->assertSame(OrderReservation::RELEASED, $reservation->refresh()->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_overlapping_reconcile_runs_finish_a_cancellation_once(): void
    {
        $orderId = $this->placeOrder();
        $this->warehouse->failNext('release', new WarehouseUnavailableException());
        $this->actingAsApi($this->makeUser('admin'));
        $this->postJson("/api/v1/orders/{$orderId}/status", ['status' => 'cancelled'])->assertStatus(503);
        $this->warehouse->afterNext('release', fn () => $this->reconcile());

        $this->reconcile();

        $this->assertSame('cancelled', Order::find($orderId)->status);
        $this->assertSame(OrderReservation::CANCELLED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->stock());
        Mail::assertQueued(OrderStatusUpdatedMail::class, 1);
    }

    /** A release of an old attempt never overwrites the row's newer attempt. */
    public function test_a_reservation_reused_by_a_retry_is_not_overwritten_by_an_older_release(): void
    {
        $reservation = $this->stalledReservation($this->makeUser());
        $oldKey = $reservation->reservation_key;

        // While reconcile releases the old key, the row is finished by the request's
        // own compensation and then reused by a retry of the same Idempotency-Key.
        $this->warehouse->afterNext('release', function () use ($reservation) {
            OrderReservation::query()->whereKey($reservation->id)->update([
                'reservation_key' => (string) Str::uuid(),
                'status' => OrderReservation::PENDING,
            ]);
        });

        $this->reconcile();

        $reservation->refresh();
        $this->assertNotSame($oldKey, $reservation->reservation_key);
        $this->assertSame(OrderReservation::PENDING, $reservation->status, 'the newer attempt was overwritten');
    }

    public function test_the_command_is_scheduled(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('warehouse:reconcile', Artisan::output());
    }
}
