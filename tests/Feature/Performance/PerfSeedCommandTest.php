<?php

namespace Tests\Feature\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Users\Models\User;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * `php artisan perf:seed` adds a large, realistic dataset for the Requirement 3
 * measurements: customers, catalog products and historical orders with items.
 * The test uses tiny counts; the demo uses the defaults (200k orders).
 */
class PerfSeedCommandTest extends ApiTestCase
{
    public function test_it_adds_the_requested_rows(): void
    {
        $this->makeCategory('Books');
        $before = ['users' => DB::table('users')->count(), 'products' => DB::table('products')->count()];

        $this->artisan('perf:seed', ['--orders' => 60, '--customers' => 5, '--products' => 12, '--chunk' => 25])
            ->assertSuccessful();

        $this->assertSame($before['users'] + 5, DB::table('users')->count());
        $this->assertSame($before['products'] + 12, DB::table('products')->count());
        $this->assertSame(60, DB::table('orders')->count());
    }

    public function test_every_order_has_items_and_a_correct_total(): void
    {
        $this->makeCategory('Books');
        $this->artisan('perf:seed', ['--orders' => 40, '--customers' => 3, '--products' => 8, '--chunk' => 7])->assertSuccessful();

        $orphans = DB::table('orders')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('order_items')->whereColumn('order_items.order_id', 'orders.id'))->count();
        $this->assertSame(0, $orphans);

        $wrongTotals = DB::table('orders')
            ->joinSub(DB::table('order_items')->selectRaw('order_id, SUM(quantity * unit_price) AS sum')->groupBy('order_id'), 'items', 'items.order_id', '=', 'orders.id')
            ->whereRaw('ABS(orders.total_amount - items.sum) > 0.001')
            ->count();
        $this->assertSame(0, $wrongTotals);
    }

    public function test_seeded_customers_are_real_customers_who_can_sign_in(): void
    {
        $this->makeCategory('Books');
        $this->artisan('perf:seed', ['--orders' => 5, '--customers' => 2, '--products' => 2])->assertSuccessful();

        $customer = User::query()->where('email', 'perf-customer-1@example.test')->firstOrFail();
        $this->assertTrue($customer->hasRole('customer'));
        $this->assertTrue(Hash::check('password123', $customer->password));
        $this->assertSame(0, DB::table('orders')->whereNotIn('user_id', User::query()->role('customer')->pluck('id'))->count());
    }

    public function test_it_refuses_orders_when_there_are_no_products(): void
    {
        $this->makeCategory('Books');

        $this->artisan('perf:seed', ['--orders' => 5, '--customers' => 1, '--products' => 0])->assertFailed();
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_it_refuses_without_categories(): void
    {
        DB::table('categories')->delete();

        $this->artisan('perf:seed', ['--orders' => 5])->assertFailed();
    }
}
