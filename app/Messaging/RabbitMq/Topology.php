<?php

namespace App\Messaging\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Declares the exchanges and queues. Declaring is idempotent, and both the
 * outbox relay and the email worker do it on connect: a message published
 * before the worker ever ran still has a durable queue to land in.
 *
 *   ecommerce.events (topic, durable)
 *     └─ order.created ─► notifications.order-created.email (quorum)
 *                            │  x-delivery-limit = max_retries
 *                            │  x-dead-letter-exchange = ecommerce.events.dlx
 *                            ▼  (after the last failed delivery)
 *   ecommerce.events.dlx (topic, durable)
 *     └─ order.created ─► notifications.order-created.email.dlq (classic, durable)
 *
 * Quorum queues are always durable and store messages on disk. The broker
 * itself counts deliveries (a NACK with requeue, or a consumer that died
 * before ACKing) and dead-letters the message once the limit is exceeded, so
 * even a message that crashes the worker every time ends up in the DLQ.
 */
class Topology
{
    public function declare(AMQPChannel $channel): void
    {
        $config = config('messaging.topology');
        $email = $config['order_created_email'];

        // passive=false, durable=true, auto_delete=false
        $channel->exchange_declare($config['exchange'], 'topic', false, true, false);
        $channel->exchange_declare($config['dead_letter_exchange'], 'topic', false, true, false);

        $channel->queue_declare($email['dead_letter_queue'], false, true, false, false, false, new AMQPTable(
            self::deadLetterQueueArguments()
        ));
        $channel->queue_bind($email['dead_letter_queue'], $config['dead_letter_exchange'], $email['routing_key']);

        $channel->queue_declare($email['queue'], false, true, false, false, false, new AMQPTable(
            self::emailQueueArguments($config['dead_letter_exchange'], $email['max_retries'])
        ));
        $channel->queue_bind($email['queue'], $config['exchange'], $email['routing_key']);
    }

    /**
     * The DLQ is a classic durable queue on purpose: RabbitMQ 4.x gives every
     * quorum queue a default delivery limit of 20, so inspecting a dead letter
     * ~20 times with "Get messages (requeue)" in the UI would silently drop it
     * (the DLQ has no DLX of its own). Classic queues have no delivery limit,
     * and dead-lettered messages stay persistent (delivery_mode 2).
     *
     * @return array<string, mixed>
     */
    public static function deadLetterQueueArguments(): array
    {
        return ['x-queue-type' => 'classic'];
    }

    /** @return array<string, mixed> */
    public static function emailQueueArguments(string $deadLetterExchange, int $maxRetries): array
    {
        return [
            'x-queue-type' => 'quorum',
            // Failed deliveries allowed before the broker dead-letters the message.
            'x-delivery-limit' => $maxRetries,
            'x-dead-letter-exchange' => $deadLetterExchange,
            // The broker keeps the message until the DLQ confirmed it (requires
            // reject-publish overflow), so dead-lettering itself cannot lose it.
            'x-dead-letter-strategy' => 'at-least-once',
            'x-overflow' => 'reject-publish',
        ];
    }
}
