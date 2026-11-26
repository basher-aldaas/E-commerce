<?php

namespace Tests\Feature\Requirement4;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Orders\Events\OrderPlaced;
use Modules\Orders\Events\OrderStatusUpdated;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * Requirement 4 (WebSockets): live order updates through Pusher.
 *
 * - a new order is broadcast to the admins' private channel;
 * - a status change is broadcast to the owner's channel and the admins' channel;
 * - only the owner / an admin may subscribe (POST /broadcasting/auth);
 * - Pusher being unreachable never fails the order itself.
 */
class OrderBroadcastTest extends ApiTestCase
{
    public function test_placing_an_order_broadcasts_it_to_the_admins_once(): void
    {
        Event::fake([OrderPlaced::class, OrderStatusUpdated::class]);
        $customer = $this->makeUser();
        $product = $this->makeProduct(['price' => 12.50, 'stock' => 5]);
        $this->actingAsApi($customer);
        $key = (string) Str::uuid();

        $response = $this->postOrder(['items' => [['product_id' => $product->id, 'quantity' => 2]]], ['Idempotency-Key' => $key]);
        $this->assertEnvelope($response, 201);
        $orderId = $response->json('data.id');

        // The same checkout retried returns the same order and is not announced again.
        $this->postOrder(['items' => [['product_id' => $product->id, 'quantity' => 2]]], ['Idempotency-Key' => $key])->assertSuccessful();

        Event::assertDispatchedTimes(OrderPlaced::class, 1);
        Event::assertDispatched(OrderPlaced::class, function (OrderPlaced $event) use ($orderId, $customer) {
            $this->assertSame(['private-admin.orders'], $this->channelNames($event));
            $this->assertSame('order.placed', $event->broadcastAs());
            $order = $event->broadcastWith()['order'];

            return $order['id'] === $orderId
                && $order['user_id'] === $customer->id
                && $order['status'] === 'pending'
                && $order['total_amount'] === '25.00'
                && $order['created_at'] !== null;
        });
        Event::assertNotDispatched(OrderStatusUpdated::class);
    }

    public function test_a_status_change_is_broadcast_to_the_owner_and_the_admins(): void
    {
        Event::fake([OrderStatusUpdated::class]);
        $customer = $this->makeUser();
        $order = $this->makeOrder($customer);
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope($this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed']), 200);

        Event::assertDispatchedTimes(OrderStatusUpdated::class, 1);
        Event::assertDispatched(OrderStatusUpdated::class, function (OrderStatusUpdated $event) use ($order, $customer) {
            $this->assertSame(["private-orders.{$customer->id}", 'private-admin.orders'], $this->channelNames($event));
            $this->assertSame('order.status.updated', $event->broadcastAs());
            $payload = $event->broadcastWith();

            return $payload['order_id'] === $order->id
                && $payload['user_id'] === $customer->id
                && $payload['status'] === 'confirmed';
        });
    }

    public function test_cancelling_a_placed_order_broadcasts_cancelled_once_after_the_stock_returns(): void
    {
        $product = $this->makeProduct(['stock' => 5]);
        $this->actingAsApi($this->makeUser());
        $orderId = $this->postOrder(['items' => [['product_id' => $product->id, 'quantity' => 2]]])->json('data.id');
        Event::fake([OrderStatusUpdated::class]);
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope($this->postJson("/api/v1/orders/{$orderId}/status", ['status' => 'cancelled']), 200);

        Event::assertDispatchedTimes(OrderStatusUpdated::class, 1);
        Event::assertDispatched(OrderStatusUpdated::class, fn (OrderStatusUpdated $event) => $event->orderId === $orderId && $event->status === 'cancelled');
    }

    public function test_a_rejected_status_change_broadcasts_nothing(): void
    {
        Event::fake([OrderStatusUpdated::class]);
        $order = $this->makeOrder($this->makeUser(), 'cancelled');
        $this->actingAsApi($this->makeUser('admin'));

        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(409);

        Event::assertNotDispatched(OrderStatusUpdated::class);
    }

    public function test_an_unreachable_pusher_does_not_fail_the_order_or_the_status_change(): void
    {
        // A real Pusher driver pointed at a closed port: every broadcast throws.
        $this->usePusher(['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false]);
        $customer = $this->makeUser();
        $product = $this->makeProduct(['stock' => 5]);
        $this->actingAsApi($customer);

        $response = $this->postOrder(['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->assertEnvelope($response, 201);
        $orderId = $response->json('data.id');

        $this->actingAsApi($this->makeUser('admin'));
        $this->assertEnvelope($this->postJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed']), 200);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'confirmed']);
    }

    public function test_only_the_owner_may_subscribe_to_their_order_channel(): void
    {
        $this->usePusher();
        $owner = $this->makeUser();
        $other = $this->makeUser();

        $this->actingAsApi($owner);
        $this->subscribe("private-orders.{$owner->id}")->assertOk()->assertJsonStructure(['auth']);

        $this->actingAsApi($other);
        $this->subscribe("private-orders.{$owner->id}")->assertForbidden();

        $this->asGuest();
        $this->subscribe("private-orders.{$owner->id}")->assertUnauthorized();
    }

    public function test_only_admins_may_subscribe_to_the_admin_orders_channel(): void
    {
        $this->usePusher();

        $this->actingAsApi($this->makeUser('admin'));
        $this->subscribe('private-admin.orders')->assertOk()->assertJsonStructure(['auth']);

        $this->actingAsApi($this->makeUser());
        $this->subscribe('private-admin.orders')->assertForbidden();
    }

    public function test_the_interface_gets_the_public_key_but_never_the_secret(): void
    {
        $this->usePusher();
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson('/api/v1/broadcasting/config');

        $this->assertEnvelope($response, 200);
        $response->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.key', 'test-key')
            ->assertJsonPath('data.cluster', 'eu')
            ->assertJsonPath('data.auth_endpoint', '/broadcasting/auth');
        $this->assertStringNotContainsString('test-secret', $response->getContent());
    }

    public function test_the_interface_is_told_when_live_updates_are_off(): void
    {
        $this->actingAsApi($this->makeUser()); // phpunit.xml: BROADCAST_CONNECTION=null

        $this->getJson('/api/v1/broadcasting/config')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.key', null);
    }

    public function test_the_web_interface_may_open_the_pusher_websocket(): void
    {
        $this->usePusher();

        $policy = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("connect-src 'self' wss://ws-eu.pusher.com;", $policy);
    }

    /** Switch broadcasting to a Pusher app with test credentials (nothing is sent unless an event is). */
    private function usePusher(array $options = []): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => '1',
            'broadcasting.connections.pusher.options' => $options + ['cluster' => 'eu'] + config('broadcasting.connections.pusher.options'),
        ]);
        $this->app->make(BroadcastManager::class)->forgetDrivers();

        // Channels were registered on the boot-time (null) broadcaster.
        require base_path('routes/channels.php');
    }

    private function subscribe(string $channel)
    {
        return $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    private function channelNames(object $event): array
    {
        return array_map(fn ($channel) => $channel->name, $event->broadcastOn());
    }
}
