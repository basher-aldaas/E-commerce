<?php

namespace App\Messaging\RabbitMq;

use App\Messaging\Outbox\OutboxMessage;
use App\Messaging\Outbox\OutboxPublisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Publishes outbox rows to RabbitMQ with the guarantees the outbox relies on:
 *
 * - delivery_mode 2 (persistent): the broker writes the message to disk;
 * - mandatory: a message no queue is bound for comes back (basic.return)
 *   instead of being silently dropped;
 * - publisher confirms: a message counts as published only when the broker
 *   sent basic.ack for it, i.e. after it is safely stored in the queue.
 */
class RabbitMqOutboxPublisher implements OutboxPublisher
{
    private ?AbstractConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @var array<string, string|null> */
    private array $results = [];

    public function __construct(
        private readonly RabbitMqConnector $connector,
        private readonly Topology $topology,
    ) {}

    public function publish(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        try {
            $channel = $this->channel();
            $this->results = [];
            $exchange = config('messaging.topology.exchange');

            foreach ($messages as $message) {
                $this->results[$message->message_id] = 'not confirmed by the broker';

                $channel->basic_publish($this->toAmqp($message), $exchange, $message->type, true);
            }

            $channel->wait_for_pending_acks_returns((float) config('messaging.outbox.confirm_timeout_seconds'));
        } catch (Throwable $e) {
            // Broker unreachable, connection lost or no confirm in time: the
            // caller keeps every row unpublished and tries again later.
            $this->disconnect();

            throw $e;
        }

        return $this->results;
    }

    public function keepAlive(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            // Sends a heartbeat when half the interval passed without a write,
            // and notices a broker that went silent.
            $this->connection->checkHeartBeat();
        } catch (Throwable) {
            // Dead connection: drop it; the next publish() reconnects.
            $this->disconnect();
        }
    }

    public function disconnect(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable) {
            // Already broken; nothing left to close.
        }

        $this->channel = null;
        $this->connection = null;
    }

    private function toAmqp(OutboxMessage $message): AMQPMessage
    {
        return new AMQPMessage($message->body(), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $message->message_id,
            'type' => $message->type,
            'timestamp' => $message->created_at?->getTimestamp() ?? time(),
            'app_id' => 'ecommerce-outbox-relay',
            'application_headers' => new AMQPTable([
                'aggregate_type' => $message->aggregate_type,
                'aggregate_id' => $message->aggregate_id,
            ]),
        ]);
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->connection?->isConnected()) {
            return $this->channel;
        }

        $this->disconnect();
        $this->connection = $this->connector->connect();
        $channel = $this->connection->channel();

        $this->topology->declare($channel);
        $channel->confirm_select();

        // A returned message is acked afterwards too, so a return must win.
        $channel->set_return_listener(function ($replyCode, $replyText, $exchange, $routingKey, AMQPMessage $message) {
            $this->results[$message->get('message_id')] = "returned by the broker: {$replyCode} {$replyText}";
        });
        $channel->set_ack_handler(function (AMQPMessage $message) {
            $id = $message->get('message_id');
            if (! str_starts_with((string) ($this->results[$id] ?? ''), 'returned')) {
                $this->results[$id] = null;
            }
        });
        $channel->set_nack_handler(function (AMQPMessage $message) {
            $this->results[$message->get('message_id')] = 'rejected by the broker (basic.nack)';
        });

        return $this->channel = $channel;
    }
}
