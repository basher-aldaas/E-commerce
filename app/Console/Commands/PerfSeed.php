<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Products\Services\CatalogCache;
use Modules\Users\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Adds a large dataset for the Requirement 3 performance work (Phase 4):
 * customers, catalog products and historical orders with their items.
 *
 * - Bulk inserts in chunks, so 200k orders take minutes, not hours.
 * - The extra products have no Warehouse stock ("Coming soon"): Laravel never
 *   writes inventory, and only the Warehouse CLI can stock them.
 * - Historical orders have no order_reservations row (they predate the
 *   Warehouse integration), so cancelling one never calls the Warehouse.
 * - Totals equal the sum of their items; prices are the products' prices.
 *
 * Run on top of `migrate:fresh --seed`. It only adds rows.
 */
class PerfSeed extends Command
{
    protected $signature = 'perf:seed
        {--orders=200000 : Historical orders to add}
        {--customers=2000 : Customers to add (perf-customer-N@example.test, password123)}
        {--products=5000 : Catalog products to add (not stocked in the Warehouse)}
        {--chunk=2000 : Rows per insert}
        {--seed=42 : Random seed}';

    protected $description = 'Add a large dataset (customers, products, orders) for performance measurements';

    private const STATUSES = ['pending' => 15, 'confirmed' => 20, 'processing' => 15, 'completed' => 45, 'cancelled' => 5];

    public function handle(CatalogCache $catalog): int
    {
        $orders = max(0, (int) $this->option('orders'));
        $customers = max(1, (int) $this->option('customers'));
        $products = max(0, (int) $this->option('products'));
        $chunk = max(1, (int) $this->option('chunk'));
        mt_srand((int) $this->option('seed'));

        $categoryIds = DB::table('categories')->pluck('id')->all();
        if ($categoryIds === []) {
            $this->error('No categories found. Run `php artisan migrate:fresh --seed` first.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $customerIds = $this->seedCustomers($customers, $chunk);
        $this->info(sprintf('Customers: +%d', count($customerIds)));

        $this->seedProducts($products, $categoryIds, $chunk);
        $this->info(sprintf('Products: +%d', $products));

        $prices = DB::table('products')->pluck('price', 'id')->map(fn ($p) => (float) $p)->all();
        if ($orders > 0 && $prices === []) {
            $this->error('No products to order. Run `php artisan migrate:fresh --seed` or pass --products.');

            return self::FAILURE;
        }
        [$orderCount, $itemCount] = $this->seedOrders($orders, $customerIds, $prices, $chunk);
        $this->info(sprintf('Orders: +%d, order items: +%d', $orderCount, $itemCount));

        // Bulk inserts skip model events, so drop the catalog cache explicitly.
        $catalog->flush();

        $this->info(sprintf('Done in %.1f s.', microtime(true) - $started));

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function seedCustomers(int $count, int $chunk): array
    {
        $password = Hash::make('password123'); // hashed once: bcrypt per row would take minutes
        $now = now();
        $first = (int) DB::table('users')->max('id') + 1;
        $offset = DB::table('users')->where('email', 'like', 'perf-customer-%')->count();
        $roleId = Role::findByName('customer', 'api')->id;

        $ids = [];
        foreach (array_chunk(range(0, $count - 1), $chunk) as $batch) {
            $users = [];
            $roles = [];
            foreach ($batch as $i) {
                $id = $first + $i;
                $n = $offset + $i + 1;
                $ids[] = $id;
                $users[] = ['id' => $id, 'name' => "Customer {$n}", 'email' => "perf-customer-{$n}@example.test", 'password' => $password, 'created_at' => $now, 'updated_at' => $now];
                $roles[] = ['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $id];
            }
            DB::transaction(function () use ($users, $roles) {
                DB::table('users')->insert($users);
                DB::table('model_has_roles')->insert($roles);
            });
        }

        return $ids;
    }

    private function seedProducts(int $count, array $categoryIds, int $chunk): void
    {
        if ($count < 1) {
            return; // range(1, 0) would count down and create two products
        }
        $now = now();
        $offset = DB::table('products')->where('name', 'like', 'Catalog item %')->count();
        foreach (array_chunk(range(1, $count), $chunk) as $batch) {
            DB::table('products')->insert(array_map(fn (int $i) => [
                'category_id' => $categoryIds[mt_rand(0, count($categoryIds) - 1)],
                'name' => 'Catalog item '.($offset + $i),
                'description' => 'Generated product for performance measurements.',
                'price' => mt_rand(199, 49999) / 100,
                'image' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $batch));
        }
    }

    /**
     * @param  list<int>  $customerIds
     * @param  array<int, float>  $prices
     * @return array{0: int, 1: int}
     */
    private function seedOrders(int $count, array $customerIds, array $prices, int $chunk): array
    {
        $productIds = array_keys($prices);
        $statuses = [];
        foreach (self::STATUSES as $status => $weight) {
            array_push($statuses, ...array_fill(0, $weight, $status));
        }

        $nextOrderId = (int) DB::table('orders')->max('id') + 1;
        $now = time();
        $items = 0;
        $bar = $this->output->createProgressBar($count);

        for ($done = 0; $done < $count; $done += $chunk) {
            $orders = [];
            $orderItems = [];
            for ($i = 0; $i < min($chunk, $count - $done); $i++) {
                $orderId = $nextOrderId++;
                // Spread over the last year, so date ordering and ranges are realistic.
                $createdAt = date('Y-m-d H:i:s', $now - mt_rand(0, 365 * 86400));
                $total = 0.0;
                $picked = [];
                for ($line = 0, $lines = mt_rand(1, 4); $line < $lines; $line++) {
                    $productId = $productIds[mt_rand(0, count($productIds) - 1)];
                    if (isset($picked[$productId])) {
                        continue;
                    }
                    $picked[$productId] = true;
                    $quantity = mt_rand(1, 3);
                    $total += $quantity * $prices[$productId];
                    $orderItems[] = ['order_id' => $orderId, 'product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $prices[$productId], 'created_at' => $createdAt, 'updated_at' => $createdAt];
                }
                $orders[] = [
                    'id' => $orderId,
                    'user_id' => $customerIds[mt_rand(0, count($customerIds) - 1)],
                    'status' => $statuses[mt_rand(0, count($statuses) - 1)],
                    'total_amount' => round($total, 2),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }
            DB::transaction(function () use ($orders, $orderItems) {
                DB::table('orders')->insert($orders);
                foreach (array_chunk($orderItems, 4000) as $part) {
                    DB::table('order_items')->insert($part);
                }
            });
            $items += count($orderItems);
            $bar->advance(count($orders));
        }
        $bar->finish();
        $this->newLine();

        return [$count, $items];
    }
}
