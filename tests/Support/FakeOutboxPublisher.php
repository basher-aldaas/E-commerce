<?php

namespace Tests\Support;

use App\Messaging\Outbox\OutboxPublisher;
use Throwable;

/** In-memory stand-in for RabbitMQ in the outbox relay tests. */
class FakeOutboxPublisher implements OutboxPublisher
{
    /** @var list<array{message_id: string, type: string, body: string}> */
    public array $published = [];

    /** @var array<string, string> message_id => error the "broker" reports */
    public array $failures = [];

    public ?Throwable $unreachable = null;

    public int $disconnects = 0;

    public function publish(array $messages): array
    {
        if ($this->unreachable !== null) {
            throw $this->unreachable;
        }

        $results = [];
        foreach ($messages as $message) {
            if (isset($this->failures[$message->message_id])) {
                $results[$message->message_id] = $this->failures[$message->message_id];

                continue;
            }

            $this->published[] = ['message_id' => $message->message_id, 'type' => $message->type, 'body' => $message->body()];
            $results[$message->message_id] = null;
        }

        return $results;
    }

    public function keepAlive(): void {}

    public function disconnect(): void
    {
        $this->disconnects++;
    }
}
