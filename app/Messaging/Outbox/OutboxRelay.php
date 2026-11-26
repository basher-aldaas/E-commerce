<?php

namespace App\Messaging\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OutboxRelay
{
    public function __construct(private readonly OutboxPublisher $publisher) {}

    public function relayBatch(): array
    {
        return DB::transaction(function () {
            $messages = OutboxMessage::query()
                ->whereNull('published_at')
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit((int) config('messaging.outbox.batch_size'))
                ->lock('for update skip locked')
                ->get();

            if ($messages->isEmpty()) {
                return ['published' => 0, 'failed' => 0];
            }

            try {
                $results = $this->publisher->publish($messages->all());
            } catch (Throwable $e) {
                $error = $e::class.': '.$e->getMessage();
                foreach ($messages as $message) {
                    $this->markFailed($message, $error);
                }
                return ['published' => 0, 'failed' => $messages->count(), 'error' => $error];
            }

            $published = 0;
            foreach ($messages as $message) {
                $error = array_key_exists($message->message_id, $results)
                    ? $results[$message->message_id]
                    : 'no result from the publisher';

                if ($error === null) {
                    $message->forceFill(['published_at' => now(), 'attempts' => $message->attempts + 1, 'last_error' => null])->save();
                    $published++;
                } else {
                    $this->markFailed($message, $error);
                }
            }

            return ['published' => $published, 'failed' => $messages->count() - $published];
        });
    }


    //تابع لمعالجة خطأ الرسالة وارجاعها للداتا مع عداد جديد
    private function markFailed(OutboxMessage $message, string $error): void
    {
        $attempts = $message->attempts + 1;
        $delay = min(2 ** min($attempts, 16), (int) config('messaging.outbox.max_backoff_seconds'));

        $message->forceFill([
            'attempts' => $attempts,
            'last_error' => Str::limit($error, 1000),
            'available_at' => now()->addSeconds($delay),
        ])->save();

        Log::warning('Outbox message not published; will retry', [
            'message_id' => $message->message_id,
            'type' => $message->type,
            'attempts' => $attempts,
            'retry_in_seconds' => $delay,
            'error' => $error,
        ]);
    }
}
