<?php

namespace Modules\Notifications\Console;

use App\Messaging\RabbitMq\RabbitMqConnector;
use App\Messaging\RabbitMq\Topology;
use Illuminate\Console\Command;
use Modules\Notifications\Messaging\OrderCreatedEmailConsumer;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * The order confirmation email worker (Requirement 4). Runs in its own
 * container (email-worker), independent of the API: stopping it only delays
 * emails, the messages wait in the durable queue.
 *
 * prefetch = 1: the broker hands this worker one unacknowledged message at a
 * time, so a crash can only ever leave one message in flight, and that one is
 * redelivered.
 */
class ConsumeOrderCreatedEmails extends Command
{
    protected $signature = 'notifications:consume-order-created';

    protected $description = 'Consume order.created events from RabbitMQ and send the confirmation emails';

    private bool $stopping = false;

    public function handle(RabbitMqConnector $connector, Topology $topology, OrderCreatedEmailConsumer $consumer): int
    {
        // Handled between messages: an email in progress is finished (and
        // ACKed) before the worker exits on `docker compose stop`.
        $this->trap([SIGTERM, SIGINT], function () {
            $this->stopping = true;
        });

        $queue = config('messaging.topology.order_created_email.queue');
        $retryInSeconds = 1;

        while (! $this->stopping) {
            $connection = null;

            try {
                $connection = $connector->connect();
                $channel = $connection->channel();
                $topology->declare($channel);
                $channel->basic_qos(0, 1, false);

                // no_ack=false: manual acknowledgements.
                $channel->basic_consume($queue, '', false, false, false, false, function (AMQPMessage $message) use ($consumer) {
                    $outcome = $consumer->handle($message);
                    $this->line(sprintf(
                        '[%s] message %s attempt %d -> %s',
                        now()->toDateTimeString(),
                        $message->has('message_id') ? $message->get('message_id') : '?',
                        OrderCreatedEmailConsumer::attempt($message),
                        $outcome->value,
                    ));
                });

                $this->info("Email worker consuming {$queue} (prefetch 1, manual ACK).");
                $retryInSeconds = 1;

                while (! $this->stopping && $channel->is_consuming()) {
                    try {
                        // Wake up every few seconds to notice a stop signal.
                        $channel->wait(null, false, 2);
                    } catch (AMQPTimeoutException) {
                        // No message in this window.
                    }
                }
            } catch (Throwable $e) {
                if ($this->stopping) {
                    break;
                }

                // Broker restarting or unreachable: unacked messages stay in the
                // queue, so just reconnect with a growing pause.
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
