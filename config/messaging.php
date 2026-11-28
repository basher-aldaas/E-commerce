<?php

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


    'topology' => [
        // Durable topic exchange every domain event is published to.
        'exchange' => env('RABBITMQ_EXCHANGE', 'ecommerce.events'),
        // Where the broker sends messages that ran out of attempts.
        'dead_letter_exchange' => env('RABBITMQ_DEAD_LETTER_EXCHANGE', 'ecommerce.events.dlx'),

        'order_created_email' => [
            'queue' => 'notifications.order-created.email',
            'dead_letter_queue' => 'notifications.order-created.email.dlq',
            'routing_key' => 'order.created',
            'max_retries' => (int) env('ORDER_EMAIL_MAX_RETRIES', 3),
            'retry_backoff_ms' => (int) env('ORDER_EMAIL_RETRY_BACKOFF_MS', 2000),
            'min_interval_ms' => (int) env('ORDER_EMAIL_MIN_INTERVAL_MS', 0),
            // Requirement 5: what the workers do with each order.created message.
            // email = send the confirmation email (Requirement 4, the default).
            // db    = a slow database task instead (OrderStatsDbTask), to load
            //         test the three workers without Mailtrap's sending limit.
            'worker_action' => env('ORDER_WORKER_ACTION', 'email'),
            'db_task_ms' => (int) env('ORDER_WORKER_DB_TASK_MS', 1000),
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
