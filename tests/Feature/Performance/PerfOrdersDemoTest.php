<?php

namespace Tests\Feature\Performance;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * GET /api/v1/perf/orders: the isolated N+1 demonstration endpoint (admin only).
 * The active implementation (A, eager loading) must use a fixed number of queries
 * whatever the page size. Implementation B (lazy loading) is commented out in
 * PerfOrdersController and is measured live; see docs/performance/DEMO.md.
 */
class PerfOrdersDemoTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCategory('Books');
        $this->artisan('perf:seed', ['--orders' => 40, '--customers' => 6, '--products' => 10])->assertSuccessful();
    }

    private function list(int $perPage): TestResponse
    {
        $this->actingAsApi($this->makeUser('admin'));

        return $this->getJson("/api/v1/perf/orders?per_page={$perPage}");
    }

    public function test_the_eager_implementation_uses_the_same_number_of_queries_for_any_page_size(): void
    {
        $small = $this->list(5)->assertOk();
        $large = $this->list(30)->assertOk();

        $small->assertJsonPath('meta.implementation', 'A: eager loading')->assertJsonCount(5, 'data.orders');
        $large->assertJsonCount(30, 'data.orders');
        // count + orders + customers + items + products
        $this->assertSame(5, $small->json('meta.queries'));
        $this->assertSame(5, $large->json('meta.queries'));
    }

    public function test_each_order_carries_its_customer_items_and_product_names(): void
    {
        $order = $this->list(3)->json('data.orders.0');

        $this->assertSame(['id', 'status', 'total_amount', 'created_at', 'customer', 'items'], array_keys($order));
        $this->assertSame(['id', 'name'], array_keys($order['customer']));
        $this->assertNotEmpty($order['items']);
        $this->assertSame(['product_id', 'product_name', 'quantity', 'unit_price'], array_keys($order['items'][0]));
        $this->assertIsString($order['items'][0]['product_name']);
        $this->assertIsString($order['customer']['name']);
    }

    public function test_it_reports_timing_and_a_hash_to_compare_both_implementations(): void
    {
        $response = $this->list(10);

        $this->assertIsFloat($response->json('meta.db_ms') + 0.0);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('meta.data_sha256'));
        $this->assertSame(hash('sha256', json_encode($response->json('data.orders'))), $response->json('meta.data_sha256'));
        $this->assertMatchesRegularExpression('/db;dur=[\d.]+;desc="queries=5"/', (string) $response->headers->get('Server-Timing'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_only_admins_can_use_it(): void
    {
        $this->actingAsApi($this->makeUser('customer'));
        $this->getJson('/api/v1/perf/orders')->assertStatus(403);
    }

    public function test_page_size_is_bounded(): void
    {
        $this->list(500)->assertStatus(422);
    }
}
