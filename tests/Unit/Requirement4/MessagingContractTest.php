<?php

namespace Tests\Unit\Requirement4;

use App\Messaging\RabbitMq\Topology;
use Modules\Notifications\Messaging\InvalidMessageException;
use Modules\Notifications\Messaging\OrderCreatedMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MessagingContractTest extends TestCase
{
    public function test_the_email_queue_is_a_quorum_queue_with_a_delivery_limit_and_a_dead_letter_exchange(): void
    {
        $this->assertSame([
            'x-queue-type' => 'quorum',
            'x-delivery-limit' => 3,
            'x-dead-letter-exchange' => 'ecommerce.events.dlx',
            'x-dead-letter-strategy' => 'at-least-once',
            'x-overflow' => 'reject-publish',
        ], Topology::emailQueueArguments('ecommerce.events.dlx', 3));
    }

    public function test_the_dead_letter_queue_is_classic_so_inspecting_it_never_drops_a_message(): void
    {
        $this->assertSame(['x-queue-type' => 'classic'], Topology::deadLetterQueueArguments());
    }

    public function test_a_valid_order_created_event_is_parsed(): void
    {
        $message = OrderCreatedMessage::fromJson(json_encode([
            'event_id' => 'e-1',
            'event_type' => 'order.created',
            'occurred_at' => '2026-11-17T10:00:00+00:00',
            'data' => [
                'order' => ['id' => 5, 'status' => 'pending', 'total_amount' => '9.00', 'items' => [
                    ['product_id' => 3, 'name' => null, 'quantity' => 3, 'unit_price' => '3.00'],
                ]],
                'customer' => ['id' => 1, 'name' => 'A', 'email' => 'a@example.test'],
            ],
        ]));

        $this->assertSame('e-1', $message->eventId);
        $this->assertSame(5, $message->orderId);
        $this->assertSame('a@example.test', $message->customerEmail);
        $this->assertSame([['product_id' => 3, 'name' => null, 'quantity' => 3, 'unit_price' => '3.00']], $message->items);
    }

    /** @return array<string, array{string}> */
    public static function invalidBodies(): array
    {
        return [
            'not json' => ['{oops'],
            'other event' => [json_encode(['event_type' => 'order.updated'])],
            'no customer' => [json_encode(['event_id' => 'e', 'event_type' => 'order.created', 'data' => ['order' => ['id' => 1, 'items' => []]]])],
            'bad email' => [json_encode(['event_id' => 'e', 'event_type' => 'order.created', 'data' => ['order' => ['id' => 1, 'items' => []], 'customer' => ['email' => 'x']]])],
            'no order id' => [json_encode(['event_id' => 'e', 'event_type' => 'order.created', 'data' => ['order' => ['items' => []], 'customer' => ['email' => 'a@example.test']]])],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused(string $body): void
    {
        $this->expectException(InvalidMessageException::class);

        OrderCreatedMessage::fromJson($body);
    }
}
