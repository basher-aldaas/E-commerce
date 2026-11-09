<?php

namespace Modules\Orders\Console;

use Illuminate\Console\Command;
use Modules\Orders\Services\OrderStockService;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class ReconcileOrderReservations extends Command
{
    protected $signature = 'warehouse:reconcile {--older-than=2 : Only touch reservations idle for this many minutes}';

    protected $description = 'Finish Warehouse reservations left unsettled by a failure or crash';

    public function handle(OrderStockService $stock): int
    {
        if (! config('warehouse.enabled')) {
            $this->info('The Warehouse integration is disabled. Nothing to do.');

            return SymfonyCommand::SUCCESS;
        }

        $counts = $stock->reconcile((int) $this->option('older-than'));

        $this->info("Settled {$counts['settled']}, still unsettled {$counts['failed']}.");

        return SymfonyCommand::SUCCESS;
    }
}
