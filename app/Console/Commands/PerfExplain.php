<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Models\Product;

/**
 * Shows how MySQL runs the app's heaviest list queries: the plan (EXPLAIN), the
 * real execution (EXPLAIN ANALYZE) and a measured median time. Run it before and
 * after `php artisan migrate` / `migrate:rollback --step=1` to compare indexes.
 *
 * Each query is built with the same Eloquent chain the service uses
 * (OrderService::getOrders, OrderService::getOrder, ProductService::queryV2), so
 * the SQL measured here is the SQL the API sends.
 */
class PerfExplain extends Command
{
    protected $signature = 'perf:explain {--runs=5 : Timed runs per query (median reported)}';

    protected $description = 'EXPLAIN and time the order and product list queries';

    public function handle(): int
    {
        $runs = max(1, (int) $this->option('runs'));

        // The busiest customer and a real order, so the plans use realistic values.
        $customerId = Order::query()->select('user_id')->groupBy('user_id')->orderByRaw('COUNT(*) DESC')->value('user_id');
        $orderId = Order::query()->max('id');
        if ($customerId === null || $orderId === null) {
            $this->error('No orders to explain. Run `php artisan perf:seed` first.');

            return self::FAILURE;
        }
        $categoryId = Product::query()->select('category_id')->groupBy('category_id')->orderByRaw('COUNT(*) DESC')->value('category_id');

        $this->line(sprintf('Orders: %d · order items: %d · products: %d · MySQL %s',
            Order::query()->count(), OrderItem::query()->count(), Product::query()->count(), DB::selectOne('SELECT VERSION() AS v')->v));
        $this->newLine();

        $queries = [
            'Admin orders, page 1' => Order::query()->latest()->orderByDesc('id')->limit(15)->offset(0),
            'Admin orders, total count' => Order::query()->selectRaw('count(*) as aggregate'),
            'Customer orders, page 1' => Order::query()->where('user_id', $customerId)->latest()->orderByDesc('id')->limit(15)->offset(0),
            'Customer orders, total count' => Order::query()->where('user_id', $customerId)->selectRaw('count(*) as aggregate'),
            'Items of one order' => OrderItem::query()->whereIn('order_id', [$orderId]),
            'Products v2, category + price range, page 1' => Product::query()
                ->where('is_active', true)->where('category_id', $categoryId)
                ->where('price', '>=', 10)->where('price', '<=', 200)
                ->latest()->orderByDesc('id')->limit(12)->offset(0),
        ];

        foreach ($queries as $label => $builder) {
            $this->explain($label, $builder, $runs);
        }

        return self::SUCCESS;
    }

    private function explain(string $label, Builder $builder, int $runs): void
    {
        $sql = $builder->toRawSql();
        $this->info("■ {$label}");
        $this->line("  {$sql}");

        foreach (DB::select("EXPLAIN {$sql}") as $row) {
            $this->line(sprintf('  table: %s · type: %s · key: %s · rows: %s · filtered: %s · Extra: %s',
                $row->table, $row->type ?? 'NULL', $row->key ?? 'NULL', $row->rows ?? 'NULL', $row->filtered ?? 'NULL', $row->Extra ?? ''));
        }

        // EXPLAIN ANALYZE runs the query and reports the real time per step.
        $analyze = DB::selectOne("EXPLAIN ANALYZE {$sql}");
        foreach (explode("\n", trim((string) array_values((array) $analyze)[0])) as $line) {
            $this->line('  '.$line);
        }

        $times = [];
        for ($i = 0; $i < $runs; $i++) {
            $started = hrtime(true);
            DB::select($sql);
            $times[] = (hrtime(true) - $started) / 1e6;
        }
        sort($times);
        $median = $times[intdiv(count($times), 2)];
        $this->line(sprintf('  median %.2f ms over %d runs', $median, $runs));
        $this->newLine();
    }
}
