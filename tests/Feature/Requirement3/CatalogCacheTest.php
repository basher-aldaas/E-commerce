<?php

namespace Tests\Feature\Requirement3;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * Catalog cache (Redis in Docker, the array store here), invalidation, TTL, the
 * cache-off and bypass switches, and ETag / 304, on API v1 and v2.
 */
class CatalogCacheTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['products.cache.enabled' => true, 'products.cache.allow_bypass_header' => true, 'products.cache.ttl' => 300]);
        Cache::flush();
        $this->actingAsApi($this->makeUser());
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = collect(DB::getQueryLog())
            ->filter(fn ($q) => preg_match('/\bfrom `(products|categories)`/', $q['query']))
            ->count();
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<string, array{0: string}> */
    public static function catalogEndpoints(): array
    {
        return [
            'v1 list' => ['/api/v1/products'],
            'v2 list' => ['/api/v2/products?category_id=%c'],
            'v1 show' => ['/api/v1/products/%p'],
            'v2 show' => ['/api/v2/products/%p'],
            'categories' => ['/api/v1/categories'],
        ];
    }

    private function url(string $template, int $productId, int $categoryId): string
    {
        return str_replace(['%p', '%c'], [$productId, $categoryId], $template);
    }

    /** @dataProvider catalogEndpoints */
    #[\PHPUnit\Framework\Attributes\DataProvider('catalogEndpoints')]
    public function test_first_read_is_a_miss_and_the_next_one_a_hit_without_catalog_queries(string $template): void
    {
        $product = $this->makeProduct();
        $url = $this->url($template, $product->id, $product->category_id);

        $this->getJson($url)->assertOk()->assertHeader('X-Cache', 'MISS');

        $queries = $this->countQueries(fn () => $this->getJson($url)->assertOk()->assertHeader('X-Cache', 'HIT'));
        $this->assertSame(0, $queries, 'A hit must not query products or categories');
    }

    public function test_live_stock_is_never_cached(): void
    {
        $product = $this->makeProduct(['stock' => 7]);

        $this->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.stock', 7);
        $this->warehouse->stock[$product->id] = 2;

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertHeader('X-Cache', 'HIT')
            ->assertJsonPath('data.stock', 2);
        $this->getJson('/api/v2/products')
            ->assertJsonPath('data.0.stock', 2);
        $this->getJson('/api/v2/products')
            ->assertHeader('X-Cache', 'HIT')
            ->assertJsonPath('data.0.stock', 2);
    }

    public function test_the_in_stock_filter_depends_on_live_stock_so_it_is_never_cached(): void
    {
        $product = $this->makeProduct(['stock' => 1]);

        $this->getJson('/api/v2/products?in_stock=1')->assertHeader('X-Cache', 'SKIP')->assertJsonPath('pagination.total', 1);
        $this->warehouse->stock[$product->id] = 0;

        $this->getJson('/api/v2/products?in_stock=1')->assertHeader('X-Cache', 'SKIP')->assertJsonPath('pagination.total', 0);
    }

    public function test_pages_and_filters_have_separate_entries(): void
    {
        $category = $this->makeCategory();
        $other = $this->makeCategory();
        foreach (range(1, 16) as $i) {
            $this->makeProduct(['category_id' => $category->id, 'name' => "Paged {$i}"]);
        }
        $this->makeProduct(['category_id' => $other->id, 'name' => 'Other category']);

        $page1 = $this->getJson('/api/v1/products?page=1')->assertHeader('X-Cache', 'MISS');
        $page2 = $this->getJson('/api/v1/products?page=2')->assertHeader('X-Cache', 'MISS');
        $this->assertNotSame($page1->json('data.0.id'), $page2->json('data.0.id'));

        $this->getJson("/api/v2/products?category_id={$category->id}")->assertHeader('X-Cache', 'MISS');
        $this->getJson("/api/v2/products?category_id={$other->id}")
            ->assertHeader('X-Cache', 'MISS')
            ->assertJsonPath('data.0.name', 'Other category');
    }

    public function test_creating_a_product_invalidates_lists_on_v1_and_v2(): void
    {
        $category = $this->makeCategory();
        $this->makeProduct(['category_id' => $category->id]);
        $this->getJson('/api/v1/products')->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/v2/products')->assertJsonPath('pagination.total', 1);

        $this->actingAsApi($this->makeUser('admin'));
        $this->postJson('/api/v1/products', ['name' => 'Fresh', 'price' => 3, 'category_id' => $category->id])->assertCreated();

        $this->getJson('/api/v1/products')->assertHeader('X-Cache', 'MISS')->assertJsonPath('pagination.total', 2);
        $this->getJson('/api/v2/products')->assertHeader('X-Cache', 'MISS')->assertJsonPath('pagination.total', 2);
    }

    public function test_updating_a_product_invalidates_its_cached_details(): void
    {
        $product = $this->makeProduct(['name' => 'Old name']);
        $this->getJson("/api/v2/products/{$product->id}")->assertJsonPath('data.name', 'Old name');

        $product->update(['name' => 'New name']);

        $this->getJson("/api/v2/products/{$product->id}")
            ->assertHeader('X-Cache', 'MISS')
            ->assertJsonPath('data.name', 'New name');
    }

    /** The Requirement 3 proof: edit a product through the API and see it at once. */
    public function test_an_api_edit_is_visible_at_once_everywhere(): void
    {
        $product = $this->makeProduct(['name' => 'Before edit']);
        $customer = $this->makeUser();
        $urls = ["/api/v1/products/{$product->id}", "/api/v2/products/{$product->id}", '/api/v1/products', '/api/v2/products'];
        $etags = [];
        foreach ($urls as $url) {
            $this->actingAsApi($customer);
            $this->getJson($url);
            $this->actingAsApi($customer);
            $etags[$url] = $this->getJson($url)->assertHeader('X-Cache', 'HIT')->headers->get('ETag');
        }

        $this->actingAsApi($this->makeUser('admin'));
        $this->putJson("/api/v1/products/{$product->id}", [
            'name' => 'After edit',
            'price' => 10,
            'category_id' => $product->category_id,
        ])->assertOk();

        // The old ETag no longer matches (200, not 304) and the body has the edit.
        // v1 and v2 details share one cache entry, so the first read after the
        // edit refills it (MISS) and the second one reads that fresh entry (HIT).
        $seen = [];
        foreach ($urls as $url) {
            $this->actingAsApi($customer);
            $response = $this->getJson($url, ['If-None-Match' => $etags[$url]]);
            $response->assertOk();
            $seen[$url] = $response->headers->get('X-Cache');
            $this->assertStringContainsString('After edit', $response->getContent(), $url);
            $this->assertNotSame($etags[$url], $response->headers->get('ETag'), $url);
        }
        $this->assertSame([
            "/api/v1/products/{$product->id}" => 'MISS',
            "/api/v2/products/{$product->id}" => 'HIT',
            '/api/v1/products' => 'MISS',
            '/api/v2/products' => 'MISS',
        ], $seen);
    }

    public function test_creating_a_category_invalidates_the_category_list(): void
    {
        $this->makeCategory('First');
        $this->getJson('/api/v1/categories')->assertJsonPath('data.total', 1);

        $this->actingAsApi($this->makeUser('admin'));
        $this->postJson('/api/v1/categories', ['name' => 'Second'])->assertCreated();

        $this->getJson('/api/v1/categories')->assertHeader('X-Cache', 'MISS')->assertJsonPath('data.total', 2);
    }

    public function test_entries_expire_after_the_ttl(): void
    {
        config(['products.cache.ttl' => 60]);
        $product = $this->makeProduct();
        $this->getJson("/api/v1/products/{$product->id}")->assertHeader('X-Cache', 'MISS');

        $start = now();

        $this->travelTo($start->copy()->addSeconds(59));
        $this->getJson("/api/v1/products/{$product->id}")->assertHeader('X-Cache', 'HIT');

        $this->travelTo($start->copy()->addSeconds(61));
        $this->getJson("/api/v1/products/{$product->id}")->assertHeader('X-Cache', 'MISS');
    }

    public function test_a_missing_product_is_never_cached(): void
    {
        $this->getJson('/api/v1/products/999999')->assertNotFound();

        $product = $this->makeProduct();

        $this->getJson("/api/v1/products/{$product->id}")->assertOk();
    }

    public function test_the_bypass_header_skips_the_cache_for_that_request_only(): void
    {
        $product = $this->makeProduct();
        $this->getJson("/api/v1/products/{$product->id}")->assertHeader('X-Cache', 'MISS');

        $queries = $this->countQueries(fn () => $this->getJson("/api/v1/products/{$product->id}", ['X-Cache-Bypass' => '1'])
            ->assertHeader('X-Cache', 'BYPASS'));
        $this->assertGreaterThan(0, $queries, 'A bypassed read must hit MySQL');

        $this->getJson("/api/v1/products/{$product->id}")->assertHeader('X-Cache', 'HIT');
    }

    public function test_the_bypass_header_is_ignored_when_not_allowed(): void
    {
        config(['products.cache.allow_bypass_header' => false]);
        $product = $this->makeProduct();
        $this->getJson("/api/v1/products/{$product->id}");

        $this->getJson("/api/v1/products/{$product->id}", ['X-Cache-Bypass' => '1'])->assertHeader('X-Cache', 'HIT');
    }

    public function test_the_cache_can_be_switched_off(): void
    {
        config(['products.cache.enabled' => false]);
        $product = $this->makeProduct();

        $this->getJson("/api/v1/products/{$product->id}")->assertHeader('X-Cache', 'OFF');
        $this->getJson("/api/v1/products/{$product->id}")->assertHeader('X-Cache', 'OFF');
        $this->getJson('/api/v2/products')->assertHeader('X-Cache', 'OFF');
    }

    public function test_server_timing_separates_catalog_and_live_stock_time(): void
    {
        $product = $this->makeProduct();

        $header = $this->getJson("/api/v1/products/{$product->id}")->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/^catalog;dur=[\d.]+;desc="MISS", stock;dur=[\d.]+$/', $header);
    }

    public function test_orders_are_never_cached(): void
    {
        $owner = $this->makeUser();
        $order = $this->makeOrder($owner);
        $this->actingAsApi($owner);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertOk();
        $this->assertNull($response->headers->get('X-Cache'));
        $this->assertNull($response->headers->get('ETag'));
        Order::query()->whereKey($order->id)->update(['status' => 'processing']);
        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.status', 'processing');
    }

    /** @dataProvider catalogEndpoints */
    #[\PHPUnit\Framework\Attributes\DataProvider('catalogEndpoints')]
    public function test_etag_gives_304_until_the_representation_changes(string $template): void
    {
        $product = $this->makeProduct(['stock' => 5]);
        $url = $this->url($template, $product->id, $product->category_id);

        $first = $this->getJson($url)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));

        $notModified = $this->getJson($url, ['If-None-Match' => $etag]);
        $notModified->assertStatus(304);
        $this->assertSame('', $notModified->getContent());

        // A real change (stock, product data, a new category) must produce a new
        // representation, so the old ETag can no longer give a 304.
        $this->warehouse->stock[$product->id] = 4;
        $product->update(['description' => 'Changed']);
        $this->makeCategory('Added after the first read');

        $changed = $this->getJson($url, ['If-None-Match' => $etag]);
        $changed->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
    }

    public function test_the_etag_ignores_the_per_request_timestamp(): void
    {
        $product = $this->makeProduct();

        $first = $this->getJson("/api/v1/products/{$product->id}")->headers->get('ETag');
        $this->travel(5)->seconds();
        $second = $this->getJson("/api/v1/products/{$product->id}")->headers->get('ETag');

        $this->assertSame($first, $second);
    }
}
