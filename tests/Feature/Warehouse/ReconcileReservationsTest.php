<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Modules\Orders\Mail\OrderStatusUpdatedMail;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Models\Product;
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

        return (int) $this->postJson('/api/v1/orders', [
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
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'cart-1'])->assertStatus(504);
        $this->actingAsApi($user);
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'cart-1'])->assertStatus(409);

        $this->reconcile();

        $this->actingAsApi($user);
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'cart-1'])->assertStatus(201);
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
            'items' => [$this->product->id => 2],
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
            'items' => [$this->product->id => 2],
            'status' => OrderReservation::PENDING,
        ]);

        $this->reconcile(olderThan: 2);

        $this->assertSame(OrderReservation::PENDING, OrderReservation::sole()->status);
        $this->assertSame([], $this->warehouse->callsTo('release'));
    }

    public function test_an_unacknowledged_confirm_is_finished_and_the_order_kept(): void
    {
        $this->warehouse->failNext('confirm', new WarehouseTimeoutException());
        $orderId = $this->placeOrder();
        $this->assertSame(OrderReservation::SAVED, OrderReservation::sole()->status);

        $this->reconcile();

        $reservation = OrderReservation::sole();
        $this->assertSame(OrderReservation::CONFIRMED, $reservation->status);
        $this->assertSame('CONFIRMED', $this->warehouse->reservations[$reservation->reservation_key]['status']);
        $this->assertNotNull(Order::find($orderId));
        $this->assertSame(8, $this->stock());
    }

    public function test_an_unfinished_cancellation_is_completed_and_the_customer_notified(): void
    {
        $orderId = $this->placeOrder();
        $this->warehouse->failNext('returnStock', new WarehouseUnavailableException());
        $this->actingAsApi($this->makeUser('admin'));
        $this->postJson("/api/v1/orders/{$orderId}/status", ['status' => 'cancelled'])->assertStatus(503);

        $this->reconcile();

        $this->assertSame('cancelled', Order::find($orderId)->status);
        $this->assertSame(OrderReservation::RETURNED, OrderReservation::sole()->status);
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
        $this->warehouse->failNext('confirm', new WarehouseTimeoutException(), times: 2);
        $this->placeOrder();

        $output = $this->reconcile();

        $this->assertStringContainsString('still unsettled 1', $output);
        $this->assertSame(OrderReservation::SAVED, OrderReservation::sole()->status);

        $this->reconcile();

        $this->assertSame(OrderReservation::CONFIRMED, OrderReservation::sole()->status);
    }

    public function test_nothing_happens_while_the_integration_is_disabled(): void
    {
        config(['warehouse.enabled' => false]);
        $this->warehouse->failNext('confirm', new WarehouseTimeoutException());
        $this->placeOrder();
        $callsBefore = count($this->warehouse->calls);

        $this->reconcile();

        $this->assertCount($callsBefore, $this->warehouse->calls);
    }

    public function test_the_command_is_scheduled(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('warehouse:reconcile', Artisan::output());
    }
}
