<?php

namespace Modules\Notifications\Console;

use App\Messaging\RabbitMq\RabbitMqConnector;
use App\Messaging\RabbitMq\Topology;
use Illuminate\Console\Command;
use Modules\Notifications\Messaging\DeadLetterReplayer;

/**
 * Operator command (Requirement 4): after fixing why emails failed, move the
 * dead letters back to the email queue so the worker tries them again.
 *
 *   docker compose exec email-worker php artisan notifications:replay-dead-letters --limit=10
 */
class ReplayOrderEmailDeadLetters extends Command
{
    protected $signature = 'notifications:replay-dead-letters
        {--limit=100 : Most messages to move in this run}';

    protected $description = 'Republish order confirmation dead letters to the email queue (confirmed before removal)';

    public function handle(RabbitMqConnector $connector, Topology $topology, DeadLetterReplayer $replayer): int
    {
        $connection = $connector->connect();

        try {
            $channel = $connection->channel();
            $topology->declare($channel);

            $report = $replayer->replay($channel, max(1, (int) $this->option('limit')));
        } finally {
            $connection->close();
        }

        foreach ($report as $row) {
            $row['replayed']
                ? $this->line("replayed {$row['message_id']}")
                : $this->error("kept in DLQ {$row['message_id']}: {$row['error']}");
        }

        $replayed = count(array_filter($report, fn ($row) => $row['replayed']));
        $this->info("{$replayed} of ".count($report).' dead letter(s) replayed.');

        return $replayed === count($report) ? self::SUCCESS : self::FAILURE;
    }
}
