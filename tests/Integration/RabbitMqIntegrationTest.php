<?php

namespace Tests\Integration;

use App\Messaging\Outbox\OutboxMessage;
use App\Messaging\RabbitMq\RabbitMqConnector;
use App\Messaging\RabbitMq\RabbitMqOutboxPublisher;
use App\Messaging\RabbitMq\Topology;
use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\TestCase;

/**
 * Requirement 4, against the real RabbitMQ container (no database needed).
 * Runs only inside the Docker network, on explicit request:
 *
 *   docker compose exec -e RABBITMQ_INTEGRATION=1 app php artisan test tests/Integration/RabbitMqIntegrationTest.php
 *
 * Uses its own exchange/queue names (test.*), deleted afterwards, so the live
 * order queues are never touched.
 */
class RabbitMqIntegrationTest extends TestCase
{
    private AbstractConnection $connection;

    private AMQPChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RABBITMQ_INTEGRATION')) {
            $this->markTestSkipped('Set RABBITMQ_INTEGRATION=1 and run inside Docker (needs the rabbitmq service).');
        }

        $suffix = Str::lower(Str::random(6));
        config([
            'messaging.topology.exchange' => "test.events.{$suffix}",
            'messaging.topology.dead_letter_exchange' => "test.events.dlx.{$suffix}",
            'messaging.topology.order_created_email.queue' => "test.email.{$suffix}",
            'messaging.topology.order_created_email.dead_letter_queue' => "test.email.dlq.{$suffix}",
            'messaging.topology.order_created_email.max_retries' => 2,
        ]);

        $this->connection = app(RabbitMqConnector::class)->connect();
        $this->channel = $this->connection->channel();
        app(Topology::class)->declare($this->channel);
    }

    protected function tearDown(): void
    {
        if (isset($this->channel)) {
            $t = config('messaging.topology');
            $this->channel->queue_delete($t['order_created_email']['queue']);
            $this->channel->queue_delete($t['order_created_email']['dead_letter_queue']);
            $this->channel->exchange_delete($t['exchange']);
            $this->channel->exchange_delete($t['dead_letter_exchange']);
            $this->connection->close();
        }

        parent::tearDown();
    }

    private function outboxRow(string $type = 'order.created'): OutboxMessage
    {
        $id = (string) Str::uuid();

        return (new OutboxMessage())->forceFill([
            'message_id' => $id,
            'type' => $type,
            'aggregate_type' => 'order',
            'aggregate_id' => '1',
            'payload' => ['event_id' => $id, 'event_type' => $type, 'data' => []],
        ]);
    }

    private function waitForMessage(string $queue): AMQPMessage
    {
        for ($i = 0; $i < 50; $i++) {
            $message = $this->channel->basic_get($queue);
            if ($message !== null) {
                return $message;
            }
            usleep(100_000);
        }

        $this->fail("No message arrived in {$queue}.");
    }

    public function test_a_confirmed_message_is_persistent_and_waits_in_the_durable_queue(): void
    {
        $publisher = app(RabbitMqOutboxPublisher::class);
        $row = $this->outboxRow();

        $this->assertSame([$row->message_id => null], $publisher->publish([$row]));
        $publisher->disconnect();

        [, $ready] = $this->channel->queue_declare(config('messaging.topology.order_created_email.queue'), true);
        $this->assertSame(1, $ready);

        $message = $this->waitForMessage(config('messaging.topology.order_created_email.queue'));
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
        $this->assertSame($row->message_id, $message->get('message_id'));
        $this->assertSame($row->payload, json_decode($message->getBody(), true));
        $message->ack();
    }

    public function test_a_message_no_queue_takes_is_reported_not_counted_as_published(): void
    {
        $publisher = app(RabbitMqOutboxPublisher::class);
        $row = $this->outboxRow('nobody.listens');

        $result = $publisher->publish([$row]);
        $publisher->disconnect();

        $this->assertStringStartsWith('returned by the broker: 312', $result[$row->message_id]);
    }

    public function test_after_the_delivery_limit_the_broker_moves_the_message_to_the_dlq(): void
    {
        $queue = config('messaging.topology.order_created_email.queue');
        $dlq = config('messaging.topology.order_created_email.dead_letter_queue');
        $row = $this->outboxRow();
        app(RabbitMqOutboxPublisher::class)->publish([$row]);

        // max_retries = 2: three deliveries, each NACKed with requeue (no ACK ever).
        $counts = [];
        for ($delivery = 1; $delivery <= 3; $delivery++) {
            $message = $this->waitForMessage($queue);
            $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : [];
            $counts[] = $headers['x-delivery-count'] ?? 0;
            $message->nack(true);
        }

        $this->assertSame([0, 1, 2], $counts, 'The broker counts each failed delivery.');

        $dead = $this->waitForMessage($dlq);
        $this->assertSame($row->message_id, $dead->get('message_id'));
        $this->assertSame($row->payload, json_decode($dead->getBody(), true));

        $headers = $dead->get('application_headers')->getNativeData();
        $this->assertSame('delivery_limit', $headers['x-first-death-reason']);
        $this->assertSame($queue, $headers['x-first-death-queue']);
        $dead->ack();

        [, $left] = $this->channel->queue_declare($queue, true);
        $this->assertSame(0, $left);
    }

    /** Publishes one message and NACKs it until the broker dead-letters it. */
    private function deadLetterOne(): OutboxMessage
    {
        $queue = config('messaging.topology.order_created_email.queue');
        $row = $this->outboxRow();
        app(RabbitMqOutboxPublisher::class)->publish([$row]);

        for ($delivery = 1; $delivery <= 3; $delivery++) {
            $this->waitForMessage($queue)->nack(true);
        }

        return $row;
    }

    public function test_a_replayed_dead_letter_goes_back_to_the_email_queue_and_leaves_the_dlq(): void
    {
        $row = $this->deadLetterOne();
        $dlq = config('messaging.topology.order_created_email.dead_letter_queue');
        $this->waitForMessage($dlq)->nack(true); // seen in the DLQ; leave it there

        $report = app(\Modules\Notifications\Messaging\DeadLetterReplayer::class)->replay($this->channel, 10);

        $this->assertSame([['message_id' => $row->message_id, 'replayed' => true, 'error' => null]], $report);
        [, $inDlq] = $this->channel->queue_declare($dlq, true);
        $this->assertSame(0, $inDlq);

        $again = $this->waitForMessage(config('messaging.topology.order_created_email.queue'));
        $headers = $again->get('application_headers')->getNativeData();
        $this->assertSame($row->message_id, $again->get('message_id'));
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $again->get('delivery_mode'));
        $this->assertSame(1, $headers['x-replay-count']);
        $this->assertSame('delivery_limit', $headers['x-replayed-death-reason']);
        $this->assertArrayNotHasKey('x-death', $headers);
        $this->assertArrayNotHasKey('x-delivery-count', $headers, 'A replayed message gets a fresh set of attempts.');
        $again->ack();
    }

    public function test_a_dead_letter_whose_copy_is_not_routable_stays_in_the_dlq(): void
    {
        $row = $this->deadLetterOne();
        $dlq = config('messaging.topology.order_created_email.dead_letter_queue');
        $this->waitForMessage($dlq)->nack(true);

        // No queue is bound for this key, so the copy would be returned.
        config(['messaging.topology.order_created_email.routing_key' => 'nobody.listens']);
        $report = app(\Modules\Notifications\Messaging\DeadLetterReplayer::class)->replay($this->channel, 10);

        $this->assertFalse($report[0]['replayed']);
        $this->assertStringStartsWith('returned by the broker: 312', $report[0]['error']);
        $kept = $this->waitForMessage($dlq);
        $this->assertSame($row->message_id, $kept->get('message_id'));
        $kept->ack();
    }
}
