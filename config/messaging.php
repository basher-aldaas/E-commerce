<?php

/*
|--------------------------------------------------------------------------
| Asynchronous messaging (Requirement 4)
|--------------------------------------------------------------------------
|
| Flow: the API writes an event into the outbox_messages table in the same
| database transaction as the order (transactional outbox). The outbox relay
| (`php artisan outbox:relay`, container outbox-relay) publishes those rows to
| RabbitMQ. The email worker (`php artisan notifications:consume-order-created`,
| container email-worker) consumes them and sends the emails.
|
| Every value that differs between environments comes from the environment;
| credentials are never written here.
|
*/

return [

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', ''),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'connection_timeout' => (float) env('RABBITMQ_CONNECTION_TIMEOUT', 3.0),
        // Must be at least twice the heartbeat (php-amqplib requirement).
        'read_write_timeout' => (float) env('RABBITMQ_READ_WRITE_TIMEOUT', 60.0),
        'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 30),
    ],

    /*
    | Topology. Declared idempotently by both the relay and the worker
    | (App\Messaging\RabbitMq\Topology), so start order does not matter.
    | Queue arguments cannot change once a queue exists: after changing
    | max_retries, delete the queue in the management UI (or use a policy).
    */
    'topology' => [
        // Durable topic exchange every domain event is published to.
        'exchange' => env('RABBITMQ_EXCHANGE', 'ecommerce.events'),
        // Where the broker sends messages that ran out of attempts.
        'dead_letter_exchange' => env('RABBITMQ_DEAD_LETTER_EXCHANGE', 'ecommerce.events.dlx'),

        'order_created_email' => [
            'queue' => 'notifications.order-created.email',
            'dead_letter_queue' => 'notifications.order-created.email.dlq',
            'routing_key' => 'order.created',
            // Failed sends retried this many times; the broker counts the
            // deliveries (quorum queue x-delivery-limit) and dead-letters the
            // message after the last one.
            'max_retries' => (int) env('ORDER_EMAIL_MAX_RETRIES', 3),
            // Worker waits backoff_ms x attempt before returning a failed message.
            'retry_backoff_ms' => (int) env('ORDER_EMAIL_RETRY_BACKOFF_MS', 2000),
            // Minimum gap between two send attempts (provider rate limits).
            // 0 = no limit. Mailtrap's free sandbox: ~15000 (10000 was still refused).
            'min_interval_ms' => (int) env('ORDER_EMAIL_MIN_INTERVAL_MS', 0),
        ],
    ],

    'outbox' => [
        // Rows claimed and published per round.
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 50),
        // Pause between rounds when there is nothing to publish.
        'poll_interval_ms' => (int) env('OUTBOX_POLL_INTERVAL_MS', 500),
        // How long to wait for the broker to confirm a batch.
        'confirm_timeout_seconds' => (float) env('OUTBOX_CONFIRM_TIMEOUT', 5.0),
        // A row that failed to publish waits 2^attempts seconds, at most this long.
        'max_backoff_seconds' => (int) env('OUTBOX_MAX_BACKOFF_SECONDS', 60),
    ],

];
