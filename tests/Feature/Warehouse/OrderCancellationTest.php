<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Mail\OrderStatusUpdatedMail;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Tests\Feature\Requirement1\ApiTestCase;
use Tests\Support\FakeWarehouseGateway;

class OrderCancellationTest extends ApiTestCase
{
    private Product $product;

    private User $customer;

    private User $admin;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->makeProduct(['stock' => 10]);
        $this->customer = $this->makeUser();
        $this->admin = $this->makeUser('admin');

        $this->actingAsApi($this->customer);
        $this->orderId = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 4]],
        ])->assertStatus(201)->json('data.id');
    }

    private function setStatus(string $status): TestResponse
    {
        $this->actingAsApi($this->admin);

        return $this->postJson("/api/v1/orders/{$this->orderId}/status", ['status' => $status]);
    }

    private function stock(): int
    {
        return $this->warehouse->stock[$this->product->id];
    }

    private function reservationStatus(): string
    {
        return OrderReservation::where('order_id', $this->orderId)->sole()->status;
    }

    public function test_cancelling_returns_the_stock_and_notifies_the_customer(): void
    {
        $this->assertSame(6, $this->stock());

        $response = $this->setStatus('cancelled');

        $this->assertEnvelope($response, 200);
        $response->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(10, $this->stock());
        $this->assertSame(OrderReservation::RETURNED, $this->reservationStatus());
        Mail::assertQueued(OrderStatusUpdatedMail::class);
    }

    public function test_the_warehouse_call_happens_outside_any_transaction(): void
    {
        $baseline = DB::transactionLevel();

        $this->setStatus('cancelled')->assertOk();

        $this->assertSame($baseline, $this->warehouse->callsTo('returnStock')[0]['transaction_level']);
    }

    public function test_cancelling_twice_returns_the_stock_once(): void
    {
        $this->setStatus('cancelled')->assertOk();
        $this->setStatus('cancelled')->assertOk();

        $this->assertSame(10, $this->stock());
        $this->assertCount(1, $this->warehouse->callsTo('returnStock'));
    }

    public function test_a_customer_cannot_cancel_through_the_status_endpoint(): void
    {
        $this->actingAsApi($this->customer);

        $response = $this->postJson("/api/v1/orders/{$this->orderId}/status", ['status' => 'cancelled']);

        $this->assertEnvelope($response, 403, success: false);
        $this->assertSame(6, $this->stock());
        $this->assertSame([], $this->warehouse->callsTo('returnStock'));
    }

    public function test_cancelling_an_order_whose_confirm_never_arrived_still_returns_stock(): void
    {
        $this->warehouse->failNext('confirm', new WarehouseTimeoutException());
        $this->actingAsApi($this->customer);
        $this->orderId = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data.id');
        $this->assertSame(OrderReservation::SAVED, $this->reservationStatus());
        $this->assertSame(5, $this->stock());

        $this->assertEnvelope($this->setStatus('cancelled'), 200);

        $this->assertSame(6, $this->stock());
        $this->assertSame(OrderReservation::RETURNED, $this->reservationStatus());
    }

    public function test_warehouse_unavailable_leaves_the_order_unchanged_and_can_be_retried(): void
    {
        $this->warehouse->failNext('returnStock', new WarehouseUnavailableException());

        $this->assertEnvelope($this->setStatus('cancelled'), 503, success: false);

        $this->assertSame('pending', Order::find($this->orderId)->status);
        $this->assertSame(OrderReservation::RETURN_PENDING, $this->reservationStatus());
        $this->assertSame(6, $this->stock());
        Mail::assertNotQueued(OrderStatusUpdatedMail::class);

        $this->assertEnvelope($this->setStatus('cancelled'), 200);

        $this->assertSame('cancelled', Order::find($this->orderId)->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_a_return_that_succeeded_but_lost_its_reply_is_not_applied_twice(): void
    {
        $this->warehouse->loseNextReply('returnStock', new WarehouseTimeoutException());

        $this->assertEnvelope($this->setStatus('cancelled'), 504, success: false);
        $this->assertSame('pending', Order::find($this->orderId)->status);
        $this->assertSame(10, $this->stock());

        $this->assertEnvelope($this->setStatus('cancelled'), 200);

        $this->assertSame('cancelled', Order::find($this->orderId)->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_another_status_is_refused_while_cancellation_is_unfinished(): void
    {
        $this->warehouse->failNext('returnStock', new WarehouseUnavailableException());
        $this->setStatus('cancelled')->assertStatus(503);

        $this->assertEnvelope($this->setStatus('processing'), 409, success: false);

        $this->assertSame('pending', Order::find($this->orderId)->status);
    }

    public function test_a_cancelled_order_cannot_be_reopened(): void
    {
        $this->setStatus('cancelled')->assertOk();

        $response = $this->setStatus('processing');

        $this->assertEnvelope($response, 409, success: false);
        $response->assertJsonPath('message', 'A cancelled order cannot be reopened.');
        $this->assertSame('cancelled', Order::find($this->orderId)->status);
    }

    public function test_a_completed_order_can_still_be_cancelled_with_its_stock_returned(): void
    {
        $this->setStatus('completed')->assertOk();

        $this->assertEnvelope($this->setStatus('cancelled'), 200);

        $this->assertSame(10, $this->stock());
    }

    public function test_other_status_changes_do_not_touch_stock(): void
    {
        $callsBefore = count($this->warehouse->calls);

        foreach (['confirmed', 'processing', 'completed', 'pending'] as $status) {
            $this->setStatus($status)->assertOk();
        }

        $this->assertCount($callsBefore, $this->warehouse->calls);
        $this->assertSame(6, $this->stock());
    }

    public function test_an_order_from_before_the_integration_cancels_without_a_return(): void
    {
        $this->orderId = $this->makeOrder($this->customer)->id;

        $this->assertEnvelope($this->setStatus('cancelled'), 200);

        $this->assertSame('cancelled', Order::find($this->orderId)->status);
        $this->assertSame([], $this->warehouse->callsTo('returnStock'));
    }

    public function test_a_refused_return_keeps_the_order_and_flags_it_for_a_person(): void
    {
        $refusing = new class extends FakeWarehouseGateway
        {
            public function returnStock(string $reservationKey): WarehouseResult
            {
                return new WarehouseResult(false, WarehouseResultCode::ReservationNotFound, 'not found');
            }
        };
        $this->app->instance(WarehouseGateway::class, $refusing);

        $this->assertEnvelope($this->setStatus('cancelled'), 500, success: false);

        $this->assertSame('pending', Order::find($this->orderId)->status);
        $this->assertSame(OrderReservation::ATTENTION, $this->reservationStatus());
    }
}
