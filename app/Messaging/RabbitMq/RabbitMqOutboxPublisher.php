<?php

namespace App\Messaging\RabbitMq;

use App\Messaging\Outbox\OutboxMessage;
use App\Messaging\Outbox\OutboxPublisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

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
            //جيب القناة الموجودة او انشأ وحدة
            $channel = $this->channel();
            $this->results = [];
            $exchange = config('messaging.topology.exchange');

            foreach ($messages as $message) {
                $this->results[$message->message_id] = 'not confirmed by the broker';

                $channel->basic_publish($this->toAmqp($message), $exchange, $message->type, true);
            }

            $channel->wait_for_pending_acks_returns((float) config('messaging.outbox.confirm_timeout_seconds'));
        } catch (Throwable $e) {
            $this->disconnect();

            throw $e;
        }

        return $this->results;
    }

    //مسؤول عن مراقبة الاتصال مع ال RabbitMQ
    public function keepAlive(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            $this->connection->checkHeartBeat();
        } catch (Throwable) {
            // Dead connection: drop it; the next publish() reconnects.
            $this->disconnect();
        }
    }

    //مسؤول عن اغلاق الاتصال والقناة
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

    //وظيفته تحويل كائن Laravel OutboxMessage إلى كائن يفهمه RabbitMQ، وهو AMQPMessage.
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

        // مستمع داخل القناة في حال الرسالة لم تجد اي queue لتدخل اليهافيعيد الرسالة للناشر
        $channel->set_return_listener(function ($replyCode, $replyText, $exchange, $routingKey, AMQPMessage $message) {
            $this->results[$message->get('message_id')] = "returned by the broker: {$replyCode} {$replyText}";
        });

        //مستمع داخل القناة يعمل عندما يرسل الrabbit رسالة ack
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
