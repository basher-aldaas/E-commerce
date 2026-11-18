<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PerfExplain extends Command
{
    protected $signature = 'perf:explain';

    protected $description = 'Compare query performance with and without indexes';

    public function handle(): int
    {
        // Find the customer with the most orders.
        $customer = DB::table('orders')
            ->select('user_id')
            ->groupBy('user_id')
            ->orderByRaw('COUNT(*) DESC')
            ->first();

        if (!$customer) {
            $this->error('No orders found.');
            return self::FAILURE;
        }

        $customerId = $customer->user_id;

        $totalOrders = DB::table('orders')->count();

        $customerOrders = DB::table('orders')
            ->where('user_id', $customerId)
            ->count();

        // Run 1: Using the composite index.
        $withIndex = $this->measureQuery(
            $customerId,
            'FORCE INDEX (orders_user_id_created_at_id_index)'
        );

        // Run 2: Ignoring secondary indexes.
        $withoutIndex = $this->measureQuery(
            $customerId,
            'IGNORE INDEX (
                orders_user_id_foreign,
                orders_created_at_id_index,
                orders_user_id_created_at_id_index
            )'
        );

        // Display results.
        $this->newLine();
        $this->info('CUSTOMER ORDERS PERFORMANCE');
        $this->line('----------------------------------------');

        $this->line("Total orders     : {$totalOrders}");
        $this->line("Customer ID      : {$customerId}");
        $this->line("Customer orders  : {$customerOrders}");

        $this->newLine();
        $this->info('WITH COMPOSITE INDEX');

        $this->line("Index used       : " . ($withIndex['plan']->key ?? 'NONE'));
        $this->line("Access type      : {$withIndex['plan']->type}");
        $this->line("Estimated rows   : {$withIndex['plan']->rows}");
        $this->line(sprintf(
            'Execution time   : %.3f ms',
            $withIndex['time']
        ));

        $this->newLine();
        $this->warn('WITHOUT SECONDARY INDEXES');

        $this->line("Index used       : " . ($withoutIndex['plan']->key ?? 'NONE'));
        $this->line("Access type      : {$withoutIndex['plan']->type}");
        $this->line("Estimated rows   : {$withoutIndex['plan']->rows}");
        $this->line(sprintf(
            'Execution time   : %.3f ms',
            $withoutIndex['time']
        ));

        $this->line('----------------------------------------');

        $difference = $withoutIndex['time'] - $withIndex['time'];

        $this->line(sprintf(
            'Time difference  : %.3f ms',
            $difference
        ));

        if ($difference > 0) {
            $this->info('The indexed query was faster in this run.');
        } else {
            $this->warn('The indexed query was not faster in this run.');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function measureQuery(int $customerId, string $indexHint): array
    {
        $sql = "
            SELECT *
            FROM orders {$indexHint}
            WHERE user_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 15
        ";

        $plan = DB::select('EXPLAIN ' . $sql, [$customerId])[0];

        $start = hrtime(true);

        DB::select($sql, [$customerId]);

        $time = (hrtime(true) - $start) / 1e6;

        return [
            'plan' => $plan,
            'time' => $time,
        ];
    }
}
