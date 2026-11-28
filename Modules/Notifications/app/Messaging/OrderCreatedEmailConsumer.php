<?php

namespace Modules\Notifications\Messaging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Services\OrderConfirmationSender;
use Modules\Notifications\Services\OrderStatsDbTask;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class OrderCreatedEmailConsumer
{
    public function __construct(
        private readonly OrderConfirmationSender $sender,
        private readonly OrderStatsDbTask $dbTask,
    ) {}

    public function handle(AMQPMessage $message): DeliveryOutcome
    {
        $attempt = self::attempt($message);
        $maxAttempts = self::maxRetries() + 1;
        $context = [
            'message_id' => $message->has('message_id') ? $message->get('message_id') : null,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'worker' => gethostname(),//اسم العامل يلي عالج الرسالة (worker1 / worker2 / worker3)
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

        //ارسال الايميلات ومعالجة الفشل
        //هون المنطق يلي بعيد الارسال
        try {
            // ORDER_WORKER_ACTION=db: مهمة قاعدة بيانات بدل الإيميل لاختبار العمال
            //تحديد سرعه ارسال الرساىل لل mailtrap  من شان ما ينحظر
            $receipt = self::usesDbTask()
                ? $this->dbTask->run($event)
                : $this->sendInTurn(fn () => $this->sender->send($event));
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

        Log::channel('order_email_worker')->info(self::usesDbTask()
            ? 'Order event processed by the database task and acknowledged'
            : 'Order confirmation email sent and acknowledged', $context + [
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

    // وقت آخر إرسال محفوظ في Redis مش بذاكرة العامل، لأنو في 3 عمال (المتطلب 5)
    // العامل بيمسك القفل وهو عم يبعت، وبيسجل الوقت بعد ما يخلص الإرسال،
    // فبين نهاية كل ايميل وبداية يلي بعدو في مهلة كاملة مهما كان عدد العمال
    private function sendInTurn(callable $send): mixed
    {
        $interval = max(0, (int) config('messaging.topology.order_created_email.min_interval_ms'));

        if ($interval === 0) {
            return $send();
        }

        // عمر القفل = المهلة + 30 ثانية للإرسال: إذا مات العامل وهو ماسكه بيتحرر لحالو
        return Cache::lock('order-email:send-slot', intdiv($interval, 1000) + 30)->block(120, function () use ($interval, $send) {
            $wait = (int) Cache::get('order-email:last-send-ms', 0) + $interval - (int) (microtime(true) * 1000);

            if ($wait > 0) {
                usleep($wait * 1000);
            }

            try {
                return $send();
            } finally {
                Cache::forever('order-email:last-send-ms', (int) (microtime(true) * 1000));
            }
        });
    }

    private static function usesDbTask(): bool
    {
        return config('messaging.topology.order_created_email.worker_action') === 'db';
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
