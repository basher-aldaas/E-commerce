<?php

namespace App\Messaging\Outbox;

/** Sends outbox rows to the message broker. */
interface OutboxPublisher
{
    /**
     * Publishes the messages and waits until the broker has confirmed them.
     *
     * @param  list<OutboxMessage>  $messages
     * @return array<string, string|null> message_id => null when the broker
     *                                    confirmed it, otherwise why it was not
     *
     * @throws \Throwable when the broker cannot be reached; nothing then counts as published
     */
    public function publish(array $messages): array;

    /**
     * Called while the relay is idle: keeps an open connection alive (AMQP
     * heartbeats are only exchanged when the client does I/O). Never throws.
     */
    public function keepAlive(): void;

    /** Closes the connection; the next publish() reconnects. */
    public function disconnect(): void;
}
