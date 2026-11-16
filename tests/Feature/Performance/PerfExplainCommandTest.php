<?php

namespace Tests\Feature\Performance;

use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * `php artisan perf:explain` shows how MySQL runs the app's heaviest list queries
 * (EXPLAIN + measured time), so indexes can be compared before and after.
 */
class PerfExplainCommandTest extends ApiTestCase
{
    public function test_it_explains_every_measured_query_with_its_plan_and_timing(): void
    {
        $this->makeCategory('Books');
        $this->artisan('perf:seed', ['--orders' => 30, '--customers' => 3, '--products' => 5])->assertSuccessful();

        $this->assertSame(0, Artisan::call('perf:explain', ['--runs' => 2]));
        $output = Artisan::output();

        foreach ([
            'Admin orders, page 1', 'Admin orders, total count',
            'Customer orders, page 1', 'Customer orders, total count',
            'Items of one order', 'Products v2, category + price range, page 1',
        ] as $label) {
            $this->assertStringContainsString($label, $output);
        }
        $this->assertStringContainsString('select * from `orders` order by `created_at` desc, `id` desc limit 15 offset 0', $output);
        $this->assertMatchesRegularExpression('/type\s*:\s*\w+/', $output);
        $this->assertMatchesRegularExpression('/median\s+[\d.]+ ms over 2 runs/', $output);
        $this->assertStringContainsString('actual time=', $output); // EXPLAIN ANALYZE
    }

    public function test_it_needs_orders_to_explain(): void
    {
        $this->artisan('perf:explain')->assertFailed();
    }
}
