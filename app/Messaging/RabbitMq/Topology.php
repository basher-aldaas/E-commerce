<?php

namespace App\Messaging\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

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

    public static function deadLetterQueueArguments(): array
    {
        //لا يمكن ان تتكرر
        return ['x-queue-type' => 'classic'];
    }

    public static function emailQueueArguments(string $deadLetterExchange, int $maxRetries): array
    {
        return [
            //يمكن ان تتكرر
            'x-queue-type' => 'quorum',
            'x-delivery-limit' => $maxRetries,
            'x-dead-letter-exchange' => $deadLetterExchange,
            'x-dead-letter-strategy' => 'at-least-once',
            'x-overflow' => 'reject-publish',
        ];
    }
}
