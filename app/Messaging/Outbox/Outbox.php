<?php

namespace App\Messaging\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Records an event in the transactional outbox. It must be called inside the
 * database transaction that makes the change the event describes: the event
 * then exists if and only if that change was committed. Nothing is sent to
 * RabbitMQ here; the outbox relay does that afterwards, outside the request.
 */
class Outbox
{
    /**
     * @param  array<string, mixed>  $data  Event data, wrapped in a versioned envelope.
     */
    public function record(string $type, string $aggregateType, string|int $aggregateId, array $data): OutboxMessage
    {
        if (DB::transactionLevel() === 0) {
            // Writing the event in its own transaction would bring back the
            // dual-write problem the outbox exists to avoid.
            throw new LogicException('Outbox::record() must run inside the transaction that changes the data.');
        }

        $messageId = (string) Str::uuid();

        return OutboxMessage::query()->create([
            'message_id' => $messageId,
            'type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'available_at' => now(),
            'payload' => [
                'event_id' => $messageId,
                'event_type' => $type,
                'event_version' => 1,
                'occurred_at' => now()->toIso8601String(),
                'data' => $data,
            ],
        ]);
    }
}
