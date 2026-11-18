<?php

namespace App\Messaging\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moves outbox rows to the broker, one batch per call.
 *
 * The rows are claimed with SELECT ... FOR UPDATE SKIP LOCKED inside a
 * transaction, so two relays never publish the same row at the same time.
 * published_at is set only for rows the broker confirmed. Anything else (broker
 * down, no confirm, message returned) keeps the row unpublished with its error
 * and an exponential backoff, and a later round tries again. A crash between
 * the broker's confirm and the commit re-publishes the row: delivery is
 * at-least-once, never at-most-once.
 */
class OutboxRelay
{
    public function __construct(private readonly OutboxPublisher $publisher) {}

    /**
     * @return array{published: int, failed: int, error?: string} error is set
     *                                                              when the broker
     *                                                              could not be used at all
     */
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

                // The failures are committed; the caller backs off before the next round.
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
