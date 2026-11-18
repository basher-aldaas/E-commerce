<?php

namespace Modules\Notifications\Messaging;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Services\OrderConfirmationSender;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Handles one `order.created` delivery with manual acknowledgements:
 *
 * - email sent             -> ACK (only now; never before the send returned);
 * - send failed            -> no ACK: NACK with requeue. The quorum queue counts
 *                             the delivery; once x-delivery-limit is exceeded the
 *                             broker dead-letters the message to the DLQ itself;
 * - message unprocessable  -> REJECT without requeue: straight to the DLQ,
 *                             retrying a malformed message cannot help.
 *
 * If the process dies before answering, the broker gets no ACK and redelivers
 * the message to the next consumer (also counted as a failed delivery).
 *
 * Every failure is written to the order_email_worker log channel with the
 * event id, attempt number and error, so a message found in the DLQ can be
 * matched with the reason it failed.
 */
class OrderCreatedEmailConsumer
{
    /** When the previous send attempt started (microtime), for the rate limit. */
    private float $lastSendAt = 0.0;

    public function __construct(private readonly OrderConfirmationSender $sender) {}

    public function handle(AMQPMessage $message): DeliveryOutcome
    {
        $attempt = self::attempt($message);
        $maxAttempts = self::maxRetries() + 1;
        $context = [
            'message_id' => $message->has('message_id') ? $message->get('message_id') : null,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
        ];

        try {
            $event = OrderCreatedMessage::fromJson($message->getBody());
        } catch (InvalidMessageException $e) {
            Log::channel('order_email_worker')->error('Unprocessable order.created message; dead-lettering it', $context + [
                'error' => $e->getMessage(),
            ]);
            $message->reject(false);

            return DeliveryOutcome::Rejected;
        }

        $context += ['event_id' => $event->eventId, 'order_id' => $event->orderId];

        $this->respectRateLimit();

        try {
            $receipt = $this->sender->send($event);
        } catch (Throwable $e) {
            $final = $attempt >= $maxAttempts;

            Log::channel('order_email_worker')->error(
                $final
                    ? 'Order confirmation email failed on the last attempt; the broker dead-letters it to the DLQ'
                    : 'Order confirmation email failed; returning the message for a retry',
                $context + ['error_class' => $e::class, 'error' => $e->getMessage()],
            );

            // Spread the retries out a little. The message stays unacked (and
            // safe) while we wait; nothing is acknowledged on failure.
            if (! $final) {
                usleep(self::backoffMs() * $attempt * 1000);
            }

            $message->nack(true);

            return DeliveryOutcome::Retried;
        }

        $message->ack();

        Log::channel('order_email_worker')->info('Order confirmation email sent and acknowledged', $context + [
            'to' => $event->customerEmail,
        ] + $receipt);

        return DeliveryOutcome::Acked;
    }

    /**
     * 1 for the first delivery. Quorum queues add x-delivery-count to a message
     * each time it comes back after a failed delivery (NACK/requeue or a
     * consumer that disconnected without ACKing).
     */
    public static function attempt(AMQPMessage $message): int
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        $data = $headers instanceof AMQPTable ? $headers->getNativeData() : [];

        return (int) ($data['x-delivery-count'] ?? 0) + 1;
    }

    /**
     * Keeps at least min_interval_ms between two send attempts. Mail providers
     * limit the sending rate (Mailtrap's free sandbox answers "550 Too many
     * emails per second"); waiting here, with the message still unacked, is
     * cheaper than burning retries on refusals.
     */
    private function respectRateLimit(): void
    {
        $interval = max(0, (int) config('messaging.topology.order_created_email.min_interval_ms')) / 1000;
        $wait = $this->lastSendAt + $interval - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        $this->lastSendAt = microtime(true);
    }

    private static function maxRetries(): int
    {
        return (int) config('messaging.topology.order_created_email.max_retries');
    }

    private static function backoffMs(): int
    {
        return max(0, (int) config('messaging.topology.order_created_email.retry_backoff_ms'));
    }
}
