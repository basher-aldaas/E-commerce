<?php

namespace App\Messaging\Outbox;

/** Sends outbox rows to the message broker. */
interface OutboxPublisher
{
    public function publish(array $messages): array;

    public function keepAlive(): void;

    public function disconnect(): void;
}
