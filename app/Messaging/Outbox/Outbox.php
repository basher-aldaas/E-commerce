<?php

namespace App\Messaging\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class Outbox
{
    //تخزين معلومات الرسالة بالداتا بيز
    public function record(string $type, string $aggregateType, string|int $aggregateId, array $data): OutboxMessage
    {
        if (DB::transactionLevel() === 0) {
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
