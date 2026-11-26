<?php

namespace App\Console\Commands;

use App\Messaging\Outbox\OutboxPublisher;
use App\Messaging\Outbox\OutboxRelay;
use Illuminate\Console\Command;
use Throwable;

class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay
        {--once : Publish one batch and exit (no loop)}';

    protected $description = 'Publish pending transactional-outbox events to RabbitMQ';

    private bool $stopping = false;

    private ?OutboxPublisher $publisher = null;

    public function handle(OutboxRelay $relay, OutboxPublisher $publisher): int
    {
        $this->publisher = $publisher;

        $this->trap([SIGTERM, SIGINT], function () {
            $this->stopping = true;
        });

        $idleMs = max(50, (int) config('messaging.outbox.poll_interval_ms'));
        $brokerDownSeconds = 1;

        if (! $this->option('once')) {
            $this->info('Outbox relay started (Ctrl+C / docker compose stop to stop).');
        }

        do {
            try {
                $result = $relay->relayBatch();
            } catch (Throwable $e) {
                // Database trouble: nothing was published or marked; retry later.
                report($e);
                $result = ['published' => 0, 'failed' => 0, 'error' => $e::class.': '.$e->getMessage()];
            }

            if ($result['published'] > 0 || $result['failed'] > 0) {
                $this->line(sprintf('[%s] published=%d failed=%d', now()->toDateTimeString(), $result['published'], $result['failed']));
            }

            if ($this->option('once')) {
                break;
            }
            //التعامل مع توقف RabbitMQ أو قاعدة البيانات
            if (isset($result['error'])) {
                $this->warn("Broker/database unavailable, retrying in {$brokerDownSeconds}s: {$result['error']}");
                $this->pause($brokerDownSeconds * 1000);
                $brokerDownSeconds = min($brokerDownSeconds * 2, 30);

                continue;
            }

            $brokerDownSeconds = 1;

            // A full batch probably means more is waiting: go again at once.
            if ($result['published'] + $result['failed'] < (int) config('messaging.outbox.batch_size')) {
                $this->pause($idleMs);
            }
        } while (! $this->stopping);

        $publisher->disconnect();

        if (! $this->option('once')) {
            $this->info('Outbox relay stopped.');
        }

        return isset($result['error']) ? self::FAILURE : self::SUCCESS;
    }


    private function pause(int $milliseconds): void
    {
        $until = microtime(true) + $milliseconds / 1000;

        while (! $this->stopping && microtime(true) < $until) {
            $this->publisher?->keepAlive();
            usleep(50_000);
        }
    }
}
