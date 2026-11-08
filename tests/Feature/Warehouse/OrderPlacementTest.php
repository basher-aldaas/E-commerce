<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Mail\OrderConfirmationMail;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Models\Product;
use RuntimeException;
use Tests\Feature\Requirement1\ApiTestCase;
use Tests\Support\FakeWarehouseGateway;

class OrderPlacementTest extends ApiTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->makeProduct(['stock' => 10]);
        $this->actingAsApi($this->makeUser());
    }

    private function placeOrder(int $quantity = 2, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ], $headers);
    }

    private function stock(): int
    {
        return $this->warehouse->stock[$this->product->id];
    }

    private function assertNoOrderWasSaved(): void
    {
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        Mail::assertNotQueued(OrderConfirmationMail::class);
    }

    public function test_a_successful_order_reserves_saves_and_confirms(): void
    {
        $other = $this->makeProduct(['stock' => 5, 'price' => 3]);

        $response = $this->postJson('/api/v1/orders', ['items' => [
            ['product_id' => $this->product->id, 'quantity' => 2],
            ['product_id' => $other->id, 'quantity' => 1],
        ]]);

        $this->assertEnvelope($response, 201);
        $response->assertJsonPath('data.total_amount', '23.00')->assertJsonCount(2, 'data.items');

        // One Reserve call holds every item, followed by one Confirm.
        $this->assertCount(1, $this->warehouse->callsTo('reserve'));
        $this->assertSame(
            [$this->product->id => 2, $other->id => 1],
            $this->warehouse->callsTo('reserve')[0]['args'][1]
        );
        $this->assertCount(1, $this->warehouse->callsTo('confirm'));

        $reservation = OrderReservation::sole();
        $this->assertSame(OrderReservation::CONFIRMED, $reservation->status);
        $this->assertSame($response->json('data.id'), $reservation->order_id);
        $this->assertSame('CONFIRMED', $this->warehouse->reservations[$reservation->reservation_key]['status']);
        $this->assertSame(8, $this->stock());
        Mail::assertQueued(OrderConfirmationMail::class);
    }

    public function test_warehouse_calls_never_happen_inside_a_database_transaction(): void
    {
        $baseline = DB::transactionLevel();

        $this->placeOrder()->assertStatus(201);

        $this->assertNotEmpty($this->warehouse->calls);
        foreach ($this->warehouse->calls as $call) {
            $this->assertSame($baseline, $call['transaction_level'], "{$call['method']} ran inside a transaction");
        }
    }

    public function test_a_product_without_warehouse_stock_cannot_be_ordered(): void
    {
        unset($this->warehouse->stock[$this->product->id]);

        $response = $this->placeOrder();

        $this->assertEnvelope($response, 409, success: false);
        $response->assertJsonPath('data.available', 0);
        $this->assertNoOrderWasSaved();
        $this->assertSame(OrderReservation::REJECTED, OrderReservation::sole()->status);
    }

    public function test_warehouse_text_is_never_shown_to_the_user(): void
    {
        $this->assertStringNotContainsString('internal:', $this->placeOrder(99)->getContent());
    }

    public function test_warehouse_unavailable_returns_503_and_no_order(): void
    {
        $this->warehouse->failNext('reserve', new WarehouseUnavailableException());

        $response = $this->placeOrder();

        $this->assertEnvelope($response, 503, success: false);
        $this->assertNoOrderWasSaved();
        $this->assertCount(1, $this->warehouse->callsTo('release'));
        $this->assertSame(OrderReservation::RELEASED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_reserve_timeout_returns_504_and_no_order(): void
    {
        $this->warehouse->failNext('reserve', new WarehouseTimeoutException());

        $this->assertEnvelope($this->placeOrder(), 504, success: false);

        $this->assertNoOrderWasSaved();
        $this->assertSame(10, $this->stock());
    }

    public function test_a_reservation_that_succeeded_but_lost_its_reply_is_given_back(): void
    {
        $this->warehouse->loseNextReply('reserve', new WarehouseTimeoutException());

        $this->assertEnvelope($this->placeOrder(), 504, success: false);

        $this->assertNoOrderWasSaved();
        $this->assertSame(10, $this->stock());
        $this->assertSame(OrderReservation::RELEASED, OrderReservation::sole()->status);
    }

    public function test_a_late_reserve_after_the_timeout_cannot_take_stock(): void
    {
        $this->warehouse->failNext('reserve', new WarehouseTimeoutException());
        $this->placeOrder()->assertStatus(504);

        // The request that timed out finally reaches the Warehouse.
        $late = $this->warehouse->reserve(OrderReservation::sole()->reservation_key, [$this->product->id => 2]);

        $this->assertFalse($late->success);
        $this->assertSame(WarehouseResultCode::AlreadyReleased, $late->code);
        $this->assertSame(10, $this->stock());
    }

    public function test_if_release_also_fails_the_reservation_is_left_for_reconcile(): void
    {
        $this->warehouse->loseNextReply('reserve', new WarehouseTimeoutException());
        $this->warehouse->failNext('release', new WarehouseUnavailableException());

        $this->assertEnvelope($this->placeOrder(), 504, success: false);

        $this->assertNoOrderWasSaved();
        $this->assertSame(8, $this->stock());
        $this->assertSame(OrderReservation::RELEASE_PENDING, OrderReservation::sole()->status);
    }

    public function test_an_unexpected_refusal_fails_safely(): void
    {
        $refusing = new class extends FakeWarehouseGateway
        {
            public function reserve(string $reservationKey, array $quantities): WarehouseResult
            {
                return new WarehouseResult(false, WarehouseResultCode::KeyConflict, 'conflict');
            }
        };
        $refusing->stock = [$this->product->id => 10];
        $this->app->instance(WarehouseGateway::class, $refusing);

        $this->assertEnvelope($this->placeOrder(), 500, success: false);

        $this->assertNoOrderWasSaved();
        $this->assertCount(1, $refusing->callsTo('release'));
    }

    public function test_a_failed_order_save_rolls_back_and_releases_the_stock(): void
    {
        Event::listen('eloquent.creating: '.Order::class, function () {
            throw new RuntimeException('database failure');
        });

        $response = $this->placeOrder();

        $this->assertEnvelope($response, 500, success: false);
        $this->assertNoOrderWasSaved();
        $this->assertCount(1, $this->warehouse->callsTo('reserve'));
        $this->assertCount(1, $this->warehouse->callsTo('release'));
        $this->assertSame([], $this->warehouse->callsTo('confirm'));
        $this->assertSame(OrderReservation::RELEASED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_a_failure_partway_through_the_save_leaves_nothing_behind(): void
    {
        Event::listen('eloquent.creating: '.OrderItem::class, function () {
            throw new RuntimeException('database failure on the items');
        });

        $this->assertEnvelope($this->placeOrder(), 500, success: false);

        $this->assertNoOrderWasSaved();
        $reservation = OrderReservation::sole();
        $this->assertSame(OrderReservation::RELEASED, $reservation->status);
        $this->assertNull($reservation->order_id);
        $this->assertSame(10, $this->stock());
    }

    public function test_a_failed_save_with_a_failed_release_is_left_for_reconcile(): void
    {
        Event::listen('eloquent.creating: '.Order::class, function () {
            throw new RuntimeException('database failure');
        });
        $this->warehouse->failNext('release', new WarehouseTimeoutException());

        $this->assertEnvelope($this->placeOrder(), 500, success: false);

        $this->assertSame(OrderReservation::RELEASE_PENDING, OrderReservation::sole()->status);
        $this->assertSame(8, $this->stock());
    }

    public function test_a_confirm_timeout_keeps_the_order_and_is_left_for_reconcile(): void
    {
        $this->warehouse->failNext('confirm', new WarehouseTimeoutException());

        $response = $this->placeOrder();

        $this->assertEnvelope($response, 201);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(OrderReservation::SAVED, OrderReservation::sole()->status);
        $this->assertSame(8, $this->stock());
        $this->assertSame([], $this->warehouse->callsTo('release'));
        Mail::assertQueued(OrderConfirmationMail::class);
    }

    public function test_a_refused_confirm_keeps_the_order_and_flags_it_for_a_person(): void
    {
        Log::spy();
        $refusing = new class extends FakeWarehouseGateway
        {
            public function confirm(string $reservationKey): WarehouseResult
            {
                return new WarehouseResult(false, WarehouseResultCode::AlreadyReleased, 'released');
            }
        };
        $refusing->stock = [$this->product->id => 10];
        $this->app->instance(WarehouseGateway::class, $refusing);

        $this->assertEnvelope($this->placeOrder(), 201);

        $this->assertSame(OrderReservation::ATTENTION, OrderReservation::sole()->status);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_mail_failure_does_not_fail_or_undo_the_order(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('queue is down'));

        $this->assertEnvelope($this->placeOrder(), 201);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(OrderReservation::CONFIRMED, OrderReservation::sole()->status);
        $this->assertSame([], $this->warehouse->callsTo('release'));
    }

    public function test_repeating_an_idempotency_key_returns_the_same_order_once(): void
    {
        $first = $this->placeOrder(2, ['Idempotency-Key' => 'cart-1']);
        $this->actingAsApi(\Modules\Users\Models\User::find($first->json('data.user_id')));
        $second = $this->placeOrder(2, ['Idempotency-Key' => 'cart-1']);

        $this->assertEnvelope($first, 201);
        $this->assertEnvelope($second, 201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertJsonCount(1, 'data.items');
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, $this->warehouse->callsTo('reserve'));
        $this->assertSame(8, $this->stock());
        Mail::assertQueuedCount(1);
    }

    public function test_an_idempotency_key_cannot_be_reused_for_a_different_order(): void
    {
        $user = $this->makeUser();
        $this->actingAsApi($user);
        $this->placeOrder(2, ['Idempotency-Key' => 'cart-1'])->assertStatus(201);

        $this->actingAsApi($user);
        $response = $this->placeOrder(3, ['Idempotency-Key' => 'cart-1']);

        $this->assertEnvelope($response, 422, success: false);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(8, $this->stock());
    }

    public function test_idempotency_keys_are_separate_for_each_user(): void
    {
        $this->actingAsApi($this->makeUser());
        $first = $this->placeOrder(1, ['Idempotency-Key' => 'cart-1']);

        $this->actingAsApi($this->makeUser());
        $second = $this->placeOrder(1, ['Idempotency-Key' => 'cart-1']);

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_a_key_still_in_progress_is_refused_without_reserving(): void
    {
        $user = $this->makeUser();
        OrderReservation::create([
            'reservation_key' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'user_id' => $user->id,
            'idempotency_key' => hash('sha256', 'cart-1'),
            'request_hash' => hash('sha256', json_encode([$this->product->id => 2])),
            'items' => [$this->product->id => 2],
            'status' => OrderReservation::PENDING,
        ]);
        $this->actingAsApi($user);

        $this->assertEnvelope($this->placeOrder(2, ['Idempotency-Key' => 'cart-1']), 409, success: false);

        $this->assertSame([], $this->warehouse->callsTo('reserve'));
    }

    public function test_a_key_can_be_retried_after_stock_is_refilled(): void
    {
        $user = $this->makeUser();
        $this->actingAsApi($user);
        $this->placeOrder(50, ['Idempotency-Key' => 'cart-1'])->assertStatus(409);

        $this->warehouse->stock[$this->product->id] = 60;

        $this->actingAsApi($user);
        $this->assertEnvelope($this->placeOrder(50, ['Idempotency-Key' => 'cart-1']), 201);

        $this->assertSame(10, $this->stock());
        $this->assertDatabaseCount('order_reservations', 1);
    }

    public function test_a_key_can_be_retried_after_a_timeout_with_a_new_reservation(): void
    {
        $user = $this->makeUser();
        $this->warehouse->failNext('reserve', new WarehouseTimeoutException());
        $this->actingAsApi($user);
        $this->placeOrder(2, ['Idempotency-Key' => 'cart-1'])->assertStatus(504);
        $firstKey = OrderReservation::sole()->reservation_key;

        $this->actingAsApi($user);
        $this->assertEnvelope($this->placeOrder(2, ['Idempotency-Key' => 'cart-1']), 201);

        $reservation = OrderReservation::sole();
        $this->assertNotSame($firstKey, $reservation->reservation_key);
        $this->assertSame(OrderReservation::CONFIRMED, $reservation->status);
        $this->assertSame(8, $this->stock());
    }

    public function test_a_key_awaiting_release_is_refused_until_it_is_settled(): void
    {
        $user = $this->makeUser();
        $this->warehouse->loseNextReply('reserve', new WarehouseTimeoutException());
        $this->warehouse->failNext('release', new WarehouseUnavailableException());
        $this->actingAsApi($user);
        $this->placeOrder(2, ['Idempotency-Key' => 'cart-1'])->assertStatus(504);

        $this->actingAsApi($user);
        $this->assertEnvelope($this->placeOrder(2, ['Idempotency-Key' => 'cart-1']), 409, success: false);

        $this->assertSame(8, $this->stock());
    }

    public function test_without_a_key_every_request_is_a_new_order(): void
    {
        $user = $this->makeUser();
        $this->actingAsApi($user);
        $this->placeOrder()->assertStatus(201);
        $this->actingAsApi($user);
        $this->placeOrder()->assertStatus(201);

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(6, $this->stock());
    }

    public function test_the_largest_int32_quantity_is_accepted_and_one_more_is_rejected(): void
    {
        $this->assertSame(409, $this->placeOrder(WarehouseGateway::MAX_QUANTITY)->status());
        $this->assertCount(1, $this->warehouse->callsTo('reserve'));

        $tooLarge = $this->placeOrder(WarehouseGateway::MAX_QUANTITY + 1);

        $this->assertEnvelope($tooLarge, 422, success: false);
        $this->assertArrayHasKey('items.0.quantity', $tooLarge->json('data'));
        $this->assertCount(1, $this->warehouse->callsTo('reserve'));
    }

    public function test_an_invalid_warehouse_request_returns_a_generic_500(): void
    {
        $this->warehouse->failNext('reserve', new WarehouseRequestException());

        $response = $this->placeOrder();

        $this->assertEnvelope($response, 500, success: false);
        $response->assertJsonPath('message', 'The inventory request could not be processed.');
        $this->assertNoOrderWasSaved();
    }
}
