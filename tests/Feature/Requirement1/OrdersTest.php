<?php

namespace Tests\Feature\Requirement1;

use Illuminate\Support\Facades\Mail;
use Modules\Orders\Mail\OrderConfirmationMail;
use Modules\Orders\Mail\OrderStatusUpdatedMail;
use Modules\Orders\Models\Order;

class OrdersTest extends ApiTestCase
{
    public function test_user_creates_an_order_using_database_prices(): void
    {
        $user = $this->makeUser();
        $first = $this->makeProduct(['price' => 10.50]);
        $second = $this->makeProduct(['price' => 5.00]);
        $this->actingAsApi($user);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $first->id, 'quantity' => 2, 'unit_price' => 0.01],
                ['product_id' => $second->id, 'quantity' => 1],
            ],
            'total_amount' => 0.01,
        ]);

        $this->assertEnvelope($response, 201);
        $response->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_amount', '26.00')
            ->assertJsonCount(2, 'data.items');
        $this->assertDatabaseHas('order_items', [
            'product_id' => $first->id,
            'quantity' => 2,
            'unit_price' => 10.50,
        ]);
        Mail::assertQueued(OrderConfirmationMail::class);
    }

    public function test_admin_cannot_create_an_order(): void
    {
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->assertEnvelope($response, 403, success: false);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_validation_returns_422(): void
    {
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser());

        $invalidPayloads = [
            'no items' => ['items' => []],
            'unknown product' => ['items' => [['product_id' => 999999, 'quantity' => 1]]],
            'zero quantity' => ['items' => [['product_id' => $product->id, 'quantity' => 0]]],
            'duplicate product' => ['items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 1],
            ]],
        ];

        foreach ($invalidPayloads as $case => $payload) {
            $response = $this->postJson('/api/v1/orders', $payload);
            $this->assertSame(422, $response->status(), "Case: {$case}");
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_user_can_only_view_and_track_their_own_orders(): void
    {
        $owner = $this->makeUser();
        $order = $this->makeOrder($owner);

        $this->actingAsApi($owner);
        $this->assertEnvelope($this->getJson("/api/v1/orders/{$order->id}"), 200);
        $this->getJson("/api/v1/orders/{$order->id}/track")
            ->assertOk()
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.status', 'pending');

        $this->actingAsApi($this->makeUser());
        $this->assertEnvelope($this->getJson("/api/v1/orders/{$order->id}"), 403, success: false);
        $this->assertEnvelope($this->getJson("/api/v1/orders/{$order->id}/track"), 403, success: false);
    }

    public function test_admin_can_view_and_track_any_order(): void
    {
        $order = $this->makeOrder($this->makeUser());
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope($this->getJson("/api/v1/orders/{$order->id}"), 200);
        $this->assertEnvelope($this->getJson("/api/v1/orders/{$order->id}/track"), 200);
    }

    public function test_unknown_order_returns_404(): void
    {
        $this->actingAsApi($this->makeUser());

        $this->assertEnvelope($this->getJson('/api/v1/orders/999999'), 404, success: false);
    }

    public function test_order_listing_is_scoped_by_role(): void
    {
        $owner = $this->makeUser();
        $mine = $this->makeOrder($owner);
        $other = $this->makeOrder($this->makeUser());

        $this->actingAsApi($owner);
        $this->assertSame(
            [$mine->id],
            array_column($this->getJson('/api/v1/orders')->assertOk()->json('data.data'), 'id')
        );

        $this->actingAsApi($this->makeUser('admin'));
        $ids = array_column($this->getJson('/api/v1/orders')->assertOk()->json('data.data'), 'id');
        sort($ids);
        $this->assertSame([$mine->id, $other->id], $ids);
    }

    public function test_user_cannot_change_order_status(): void
    {
        $owner = $this->makeUser();
        $order = $this->makeOrder($owner);
        $this->actingAsApi($owner);

        $response = $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'completed']);

        $this->assertEnvelope($response, 403, success: false);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_admin_changes_order_status_and_the_customer_is_notified(): void
    {
        $order = $this->makeOrder($this->makeUser());
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'processing']);

        $this->assertEnvelope($response, 200);
        $response->assertJsonPath('data.status', 'processing');
        $this->assertSame('processing', Order::find($order->id)->status);
        Mail::assertQueued(OrderStatusUpdatedMail::class);
    }

    public function test_status_update_validates_the_status_and_the_order(): void
    {
        $order = $this->makeOrder($this->makeUser());
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope($this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'bogus']), 422, success: false);
        $this->assertEnvelope($this->postJson('/api/v1/orders/999999/status', ['status' => 'completed']), 404, success: false);
    }
}
