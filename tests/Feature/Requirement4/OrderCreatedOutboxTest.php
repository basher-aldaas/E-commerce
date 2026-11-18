<?php

namespace Tests\Feature\Requirement4;

use App\Messaging\Outbox\Outbox;
use App\Messaging\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Mail;
use Modules\Orders\Models\OrderReservation;
use RuntimeException;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * Requirement 4: placing an order records an order.created event in the
 * transactional outbox, in the same transaction as the order, and sends no
 * email during the request.
 */
class OrderCreatedOutboxTest extends ApiTestCase
{
    public function test_an_order_records_one_complete_order_created_event_and_sends_no_mail(): void
    {
        $customer = $this->makeUser('customer', ['name' => 'Lina Customer']);
        $first = $this->makeProduct(['name' => 'Desk Lamp', 'price' => 12.50, 'stock' => 5]);
        $second = $this->makeProduct(['name' => 'Notebook', 'price' => 3.00, 'stock' => 5]);
        $this->actingAsApi($customer);

        $response = $this->postOrder(['items' => [
            ['product_id' => $first->id, 'quantity' => 2],
            ['product_id' => $second->id, 'quantity' => 1],
        ]]);

        $this->assertEnvelope($response, 201);
        $orderId = $response->json('data.id');

        $event = OutboxMessage::sole();
        $this->assertSame('order.created', $event->type);
        $this->assertSame('order', $event->aggregate_type);
        $this->assertSame((string) $orderId, $event->aggregate_id);
        $this->assertNull($event->published_at, 'The API only stores the event; the relay publishes it.');
        $this->assertSame(0, $event->attempts);

        $payload = $event->payload;
        $this->assertSame($event->message_id, $payload['event_id']);
        $this->assertSame('order.created', $payload['event_type']);
        $this->assertSame(1, $payload['event_version']);
        $this->assertSame([
            'id' => $customer->id,
            'name' => 'Lina Customer',
            'email' => $customer->email,
        ], $payload['data']['customer']);
        $this->assertSame($orderId, $payload['data']['order']['id']);
        $this->assertSame('pending', $payload['data']['order']['status']);
        $this->assertSame('28.00', $payload['data']['order']['total_amount']);
        $this->assertEqualsCanonicalizing([
            ['product_id' => $first->id, 'name' => 'Desk Lamp', 'quantity' => 2, 'unit_price' => '12.50'],
            ['product_id' => $second->id, 'name' => 'Notebook', 'quantity' => 1, 'unit_price' => '3.00'],
        ], $payload['data']['order']['items']);

        // Nothing mailed or queued inside the HTTP request.
        Mail::assertNothingOutgoing();
    }

    public function test_if_the_event_cannot_be_stored_the_order_is_rolled_back_and_the_stock_released(): void
    {
        // Same transaction: an order without its event can never be committed.
        $this->app->instance(Outbox::class, new class extends Outbox
        {
            public function record(string $type, string $aggregateType, string|int $aggregateId, array $data): OutboxMessage
            {
                throw new RuntimeException('outbox insert failed');
            }
        });
        $product = $this->makeProduct(['stock' => 10]);
        $this->actingAsApi($this->makeUser());

        $response = $this->postOrder(['items' => [['product_id' => $product->id, 'quantity' => 2]]]);

        $this->assertEnvelope($response, 500, success: false);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(OrderReservation::RELEASED, OrderReservation::sole()->status);
        $this->assertSame(10, $this->warehouse->stock[$product->id]);
    }

    public function test_a_rejected_order_records_no_event(): void
    {
        $product = $this->makeProduct(['stock' => 1]);
        $this->actingAsApi($this->makeUser());

        $this->postOrder(['items' => [['product_id' => $product->id, 'quantity' => 5]]])->assertStatus(409);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_the_order_succeeds_even_when_mail_is_broken(): void
    {
        // The request never touches the mailer, so a broken one cannot matter.
        Mail::shouldReceive('to', 'send', 'queue', 'mailer')->andThrow(new RuntimeException('SMTP is down'));
        $product = $this->makeProduct(['stock' => 3]);
        $this->actingAsApi($this->makeUser());

        $this->assertEnvelope($this->postOrder(['items' => [['product_id' => $product->id, 'quantity' => 1]]]), 201);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }
}
