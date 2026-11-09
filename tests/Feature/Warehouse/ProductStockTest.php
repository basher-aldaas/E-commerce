<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Products\Models\Product;
use Tests\Feature\Requirement1\ApiTestCase;
use Tests\Support\FakeWarehouseGateway;

class ProductStockTest extends ApiTestCase
{
    public function test_a_stale_laravel_column_is_never_shown(): void
    {
        $product = $this->makeProduct(['stock' => 3]);
        DB::table('products')->where('id', $product->id)->update(['stock' => 999]);
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.stock', 3);
        $this->getJson("/api/v2/products/{$product->id}")->assertJsonPath('data.stock', 3);
    }

    public function test_a_product_the_warehouse_does_not_track_shows_as_sold_out(): void
    {
        $product = $this->makeProduct();
        unset($this->warehouse->stock[$product->id]);
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v2/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.in_stock', false);
    }

    public function test_a_page_of_products_uses_one_warehouse_call(): void
    {
        $category = $this->makeCategory();
        $this->makeProduct(['category_id' => $category->id, 'stock' => 1]);
        $this->makeProduct(['category_id' => $category->id, 'stock' => 2]);
        $this->makeProduct(['category_id' => $category->id, 'stock' => 3]);
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertCount(1, $this->warehouse->callsTo('getStock'));
        $stock = array_column($response->json('data'), 'stock');
        sort($stock);
        $this->assertSame([1, 2, 3], $stock);
    }

    /** @return array<string, array{0: class-string}> */
    public static function warehouseFailures(): array
    {
        return [
            'timeout' => [WarehouseTimeoutException::class],
            'unavailable' => [WarehouseUnavailableException::class],
        ];
    }

    /** @dataProvider warehouseFailures */
    #[\PHPUnit\Framework\Attributes\DataProvider('warehouseFailures')]
    public function test_product_pages_stay_available_with_null_stock_when_get_stock_fails(string $failure): void
    {
        $product = $this->makeProduct(['name' => 'Still visible', 'stock' => 5]);
        $this->actingAsApi($this->makeUser());

        $this->warehouse->failNext('getStock', new $failure());
        $v1 = $this->getJson("/api/v1/products/{$product->id}");
        $this->assertEnvelope($v1, 200);
        $v1->assertJsonPath('data.name', 'Still visible')->assertJsonPath('data.stock', null);

        $this->warehouse->failNext('getStock', new $failure());
        $v2 = $this->getJson("/api/v2/products/{$product->id}");
        $this->assertEnvelope($v2, 200);
        $v2->assertJsonPath('data.stock', null)->assertJsonPath('data.in_stock', null);
    }

    /** @dataProvider warehouseFailures */
    #[\PHPUnit\Framework\Attributes\DataProvider('warehouseFailures')]
    public function test_product_lists_stay_available_with_null_stock_when_get_stock_fails(string $failure): void
    {
        $this->makeProduct(['stock' => 5]);
        $this->actingAsApi($this->makeUser());

        $this->warehouse->failNext('getStock', new $failure());
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.stock', null);

        $this->warehouse->failNext('getStock', new $failure());
        $this->getJson('/api/v2/products')
            ->assertOk()
            ->assertJsonPath('data.0.stock', null)
            ->assertJsonPath('data.0.in_stock', null);
    }

    public function test_the_in_stock_filter_reports_a_warehouse_timeout_instead_of_a_wrong_list(): void
    {
        $this->makeProduct();
        $this->actingAsApi($this->makeUser());
        $this->warehouse->failNext('getStock', new WarehouseTimeoutException());

        $this->assertEnvelope($this->getJson('/api/v2/products?in_stock=1'), 504, success: false);
    }

    public function test_the_in_stock_filter_reports_an_unavailable_warehouse(): void
    {
        $this->makeProduct();
        $this->actingAsApi($this->makeUser());
        $this->warehouse->failNext('getStock', new WarehouseUnavailableException());

        $this->assertEnvelope($this->getJson('/api/v2/products?in_stock=1'), 503, success: false);
    }

    public function test_the_in_stock_filter_combines_with_other_filters_and_paginates_correctly(): void
    {
        $books = $this->makeCategory('Filter Books');
        $a = $this->makeProduct(['category_id' => $books->id, 'stock' => 1]);
        $b = $this->makeProduct(['category_id' => $books->id, 'stock' => 2]);
        $this->makeProduct(['category_id' => $books->id, 'stock' => 0]);
        $this->makeProduct(['stock' => 5]);
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson("/api/v2/products?in_stock=1&category_id={$books->id}&per_page=1")->assertOk();

        $response->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2);
        $this->assertContains($response->json('data.0.id'), [$a->id, $b->id]);
    }

    public function test_an_empty_catalog_makes_no_warehouse_call_and_filters_to_nothing(): void
    {
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson('/api/v2/products?in_stock=1')->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_order_details_show_warehouse_stock_for_their_products(): void
    {
        $user = $this->makeUser();
        $product = $this->makeProduct(['stock' => 10]);
        $this->actingAsApi($user);
        $id = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->json('data.id');

        $this->actingAsApi($user);
        $this->getJson("/api/v1/orders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product.stock', 6);
    }

    public function test_order_details_stay_available_with_null_stock_when_get_stock_fails(): void
    {
        $user = $this->makeUser();
        $product = $this->makeProduct();
        $this->actingAsApi($user);
        $id = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json('data.id');
        $this->warehouse->failNext('getStock', new WarehouseTimeoutException());

        $this->actingAsApi($user);
        $this->getJson("/api/v1/orders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product.stock', null);
    }

    private function productPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'New product',
            'price' => 5,
            'category_id' => $this->makeCategory()->id,
            'stock' => 12,
        ];
    }

    public function test_creating_a_product_initializes_its_stock_and_not_set_stock(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/products', $this->productPayload());

        $this->assertEnvelope($response, 201);
        $this->assertCount(1, $this->warehouse->callsTo('initializeStock'));
        $this->assertSame([], $this->warehouse->callsTo('setStock'));
        $this->assertSame([$response->json('data.id'), 12], $this->warehouse->callsTo('initializeStock')[0]['args']);
    }

    /** @dataProvider warehouseFailuresWithStatus */
    #[\PHPUnit\Framework\Attributes\DataProvider('warehouseFailuresWithStatus')]
    public function test_a_warehouse_failure_removes_the_new_product_and_its_image(string $failure, int $status): void
    {
        Storage::fake('public');
        $this->warehouse->failNext('initializeStock', new $failure());
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->post('/api/v1/products', $this->productPayload([
            'image' => UploadedFile::fake()->image('new.png'),
        ]), ['Accept' => 'application/json']);

        $this->assertEnvelope($response, $status, success: false);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    /** @return array<string, array{0: class-string, 1: int}> */
    public static function warehouseFailuresWithStatus(): array
    {
        return [
            'timeout' => [WarehouseTimeoutException::class, 504],
            'unavailable' => [WarehouseUnavailableException::class, 503],
        ];
    }

    /**
     * The id MySQL will give the next product. Not "max id + 1": rolled-back
     * inserts from earlier tests still advance AUTO_INCREMENT. Stats expiry 0
     * makes information_schema read the live counter instead of a cached value.
     */
    private function nextProductId(): int
    {
        DB::statement('SET SESSION information_schema_stats_expiry = 0');

        return (int) DB::scalar(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['products']
        );
    }

    public function test_existing_warehouse_stock_is_never_overwritten_by_a_new_product(): void
    {
        $nextId = $this->nextProductId();
        $this->warehouse->stock[$nextId] = 8;
        $category = $this->makeCategory();
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/products', $this->productPayload(['category_id' => $category->id]));

        $this->assertEnvelope($response, 409, success: false);
        $this->assertSame(8, $this->warehouse->stock[$nextId]);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_retry_that_finds_its_own_first_attempt_succeeds(): void
    {
        $nextId = $this->nextProductId();
        $this->warehouse->stock[$nextId] = 12;
        $category = $this->makeCategory();
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/products', $this->productPayload(['category_id' => $category->id]));

        $this->assertEnvelope($response, 201);
        // The id check proves the product hit the pre-existing row, so this
        // exercises the AlreadyInitialized path rather than a fresh insert.
        $response->assertJsonPath('data.id', $nextId);
        $response->assertJsonPath('data.stock', 12);
    }

    public function test_product_stock_has_an_int32_limit(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope(
            $this->postJson('/api/v1/products', $this->productPayload(['stock' => WarehouseGateway::MAX_QUANTITY + 1])),
            422,
            success: false
        );
        $this->assertDatabaseCount('products', 0);

        $this->assertEnvelope(
            $this->postJson('/api/v1/products', $this->productPayload(['stock' => WarehouseGateway::MAX_QUANTITY])),
            201
        );
    }

    public function test_inventory_updates_have_an_int32_limit(): void
    {
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope(
            $this->patchJson("/api/v1/inventory/{$product->id}", ['stock' => WarehouseGateway::MAX_QUANTITY + 1]),
            422,
            success: false
        );
        $this->assertSame([], $this->warehouse->callsTo('setStock'));

        $this->assertEnvelope(
            $this->patchJson("/api/v1/inventory/{$product->id}", ['stock' => WarehouseGateway::MAX_QUANTITY]),
            200
        );
    }

    public function test_inventory_update_for_an_unknown_product_never_reaches_the_warehouse(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope($this->patchJson('/api/v1/inventory/999999', ['stock' => 5]), 404, success: false);

        $this->assertSame([], $this->warehouse->callsTo('setStock'));
    }

    /** @dataProvider warehouseFailuresWithStatus */
    #[\PHPUnit\Framework\Attributes\DataProvider('warehouseFailuresWithStatus')]
    public function test_inventory_update_reports_warehouse_failures(string $failure, int $status): void
    {
        $product = $this->makeProduct(['stock' => 5]);
        $this->warehouse->failNext('setStock', new $failure());
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope($this->patchJson("/api/v1/inventory/{$product->id}", ['stock' => 9]), $status, success: false);

        $this->assertSame(5, $this->warehouse->stock[$product->id]);
    }

    public function test_a_refused_inventory_update_returns_a_generic_500(): void
    {
        $product = $this->makeProduct();
        $refusing = new class extends FakeWarehouseGateway
        {
            public function setStock(int $productId, int $quantity): WarehouseResult
            {
                return new WarehouseResult(false, WarehouseResultCode::InvalidState, 'secret reason');
            }
        };
        $this->app->instance(WarehouseGateway::class, $refusing);
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->patchJson("/api/v1/inventory/{$product->id}", ['stock' => 9]);

        $this->assertEnvelope($response, 500, success: false);
        $this->assertStringNotContainsString('secret reason', $response->getContent());
    }

    public function test_the_stock_column_cannot_be_mass_assigned(): void
    {
        $product = new Product(['stock' => 500]);

        $this->assertNull($product->stock);
        $this->assertArrayNotHasKey('stock', $product->getAttributes());
    }
}
