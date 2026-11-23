<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Products\Models\Product;
use Tests\Feature\Requirement1\ApiTestCase;

class ProductStockTest extends ApiTestCase
{
    public function test_a_product_the_warehouse_has_not_stocked_reads_as_not_stocked(): void
    {
        $product = $this->makeProduct();
        unset($this->warehouse->stock[$product->id]);
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.stock_status', 'not_stocked');
        $this->getJson("/api/v2/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.stock_status', 'not_stocked')
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
        $v1->assertJsonPath('data.name', 'Still visible')
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.stock_status', 'unavailable');

        $this->warehouse->failNext('getStock', new $failure());
        $v2 = $this->getJson("/api/v2/products/{$product->id}");
        $this->assertEnvelope($v2, 200);
        $v2->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.stock_status', 'unavailable')
            ->assertJsonPath('data.in_stock', null);
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
        $id = $this->postOrder([
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
        $id = $this->postOrder([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json('data.id');
        $this->warehouse->failNext('getStock', new WarehouseTimeoutException());

        $this->actingAsApi($user);
        $this->getJson("/api/v1/orders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product.stock', null);
    }

    public function test_creating_a_product_never_writes_to_the_warehouse(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/products', [
            'name' => 'New product',
            'price' => 5,
            'category_id' => $this->makeCategory()->id,
        ]);

        $this->assertEnvelope($response, 201);
        $response->assertJsonPath('data.stock_status', 'not_stocked');
        $this->assertSame([], $this->warehouse->callsTo('reserve'));
        $this->assertSame([], $this->warehouse->callsTo('release'));
    }

    /** @dataProvider warehouseFailures */
    #[\PHPUnit\Framework\Attributes\DataProvider('warehouseFailures')]
    public function test_creating_a_product_works_while_the_warehouse_is_down(string $failure): void
    {
        Storage::fake('public');
        $this->warehouse->failNext('getStock', new $failure());
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->post('/api/v1/products', [
            'name' => 'Offline product',
            'price' => 5,
            'category_id' => $this->makeCategory()->id,
            'image' => UploadedFile::fake()->image('new.png'),
        ], ['Accept' => 'application/json']);

        $this->assertEnvelope($response, 201);
        $response->assertJsonPath('data.stock_status', 'unavailable');
        $this->assertDatabaseHas('products', ['name' => 'Offline product']);
        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
    }

    public function test_the_stock_attribute_cannot_be_mass_assigned(): void
    {
        $product = new Product(['stock' => 500]);

        $this->assertNull($product->stock);
        $this->assertSame(Product::STOCK_UNAVAILABLE, $product->stock_status);
        $this->assertArrayNotHasKey('stock', $product->getAttributes());
    }
}
