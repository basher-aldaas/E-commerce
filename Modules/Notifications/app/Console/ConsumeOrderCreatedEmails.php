<?php

namespace Modules\Notifications\Console;

use App\Messaging\RabbitMq\RabbitMqConnector;
use App\Messaging\RabbitMq\Topology;
use Illuminate\Console\Command;
use Modules\Notifications\Messaging\OrderCreatedEmailConsumer;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class ConsumeOrderCreatedEmails extends Command
{
    protected $signature = 'notifications:consume-order-created';

    protected $description = 'Consume order.created events from RabbitMQ and send the confirmation emails';

    private bool $stopping = false;

    public function handle(RabbitMqConnector $connector, Topology $topology, OrderCreatedEmailConsumer $consumer): int
    {
        //اشارات ايقاف لما بوقف الدوكير
        $this->trap([SIGTERM, SIGINT], function () {
            $this->stopping = true;
        });

        $queue = config('messaging.topology.order_created_email.queue');
        //مدة الانتظار قبل محاولة اعادة الاتصال
        $retryInSeconds = 1;

        while (! $this->stopping) {
            $connection = null;

            try {
                $connection = $connector->connect();
                $channel = $connection->channel();
                $topology->declare($channel);
                $channel->basic_qos(0, 1, false);//تحديد عدد الرسائل التي يستطيع RabbitMQ تسليمها إلى المستهلك دون أن يحصل على ACK لها

                // استهلاك الرسائل من اللطابور وكل ما توصل رسالة نفذ callback
                $channel->basic_consume($queue, '', false, false, false, false, function (AMQPMessage $message) use ($consumer) {
                    $outcome = $consumer->handle($message);//ارسال الرسالة وارجاع احدى الحالات الثالثة
                    $this->line(sprintf(
                        '[%s] message %s attempt %d -> %s',
                        now()->toDateTimeString(),
                        $message->has('message_id') ? $message->get('message_id') : '?',
                        OrderCreatedEmailConsumer::attempt($message),
                        $outcome->value,
                    ));
                });

                $this->info(sprintf("Worker %s consuming %s (action: %s, prefetch 1, manual ACK).", gethostname(), $queue, config("messaging.topology.order_created_email.worker_action")));
                $retryInSeconds = 1;

                //الجزء يلي بخلي العامل يبقى مستيقظا
                while (! $this->stopping && $channel->is_consuming()) {
                    try {
                        $channel->wait(null, false, 2);
                    } catch (AMQPTimeoutException) {
                        // No message in this window.
                    }
                }
            } catch (Throwable $e) {
                if ($this->stopping) {
                    break;
                }

                $this->warn("RabbitMQ connection problem ({$e->getMessage()}); reconnecting in {$retryInSeconds}s.");
                $this->sleepUnlessStopping($retryInSeconds);
                $retryInSeconds = min($retryInSeconds * 2, 30);
            } finally {
                try {
                    $connection?->close();
                } catch (Throwable) {
                    // Already gone.
                }
            }
        }

        $this->info('Email worker stopped.');

        return self::SUCCESS;
    }

    private function sleepUnlessStopping(int $seconds): void
    {
        $until = microtime(true) + $seconds;

        while (! $this->stopping && microtime(true) < $until) {
            usleep(100_000);
        }
    }
}
