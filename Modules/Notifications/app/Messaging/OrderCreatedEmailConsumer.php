<?php

namespace Modules\Notifications\Messaging;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Services\OrderConfirmationSender;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class OrderCreatedEmailConsumer
{
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
            $event = OrderCreatedMessage::fromJson($message->getBody());//تحويل البيانات من json  الى اوبجيكت اذا كان الجيسون تالف او بريد غير صالح يرسل خطأ
        } catch (InvalidMessageException $e) {
            Log::channel('order_email_worker')->error('Unprocessable order.created message; dead-lettering it', $context + [
                'error' => $e->getMessage(),
            ]);
            $message->reject(false);

            return DeliveryOutcome::Rejected;
        }

        $context += ['event_id' => $event->eventId, 'order_id' => $event->orderId];

        //تحديد سرعه ارسال الرساىل لل mailtrap  من شان ما ينحظر
        $this->respectRateLimit();

        //ارسال الايميلات ومعالجة الفشل
        //هون المنطق يلي بعيد الارسال
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


            //تا×ير بين المحاولة والثانية
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

    public static function attempt(AMQPMessage $message): int
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        $data = $headers instanceof AMQPTable ? $headers->getNativeData() : [];

        return (int) ($data['x-delivery-count'] ?? 0) + 1;
    }

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
