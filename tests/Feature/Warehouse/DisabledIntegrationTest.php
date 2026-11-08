<?php

namespace Tests\Feature\Warehouse;

use App\Services\Warehouse\DisabledWarehouseGateway;
use App\Services\Warehouse\GrpcWarehouseGateway;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Support\Facades\Schema;
use Modules\Orders\Models\OrderReservation;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * With the integration off, the application must make no Warehouse connection.
 */
class DisabledIntegrationTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(WarehouseGateway::class, new DisabledWarehouseGateway());
    }

    public function test_the_integration_is_off_unless_explicitly_enabled(): void
    {
        $this->assertFalse(config('warehouse.enabled'));

        $this->app->forgetInstance(WarehouseGateway::class);
        $this->assertInstanceOf(DisabledWarehouseGateway::class, $this->app->make(WarehouseGateway::class));

        config(['warehouse.enabled' => true]);
        $this->app->forgetInstance(WarehouseGateway::class);
        $this->assertInstanceOf(GrpcWarehouseGateway::class, $this->app->make(WarehouseGateway::class));
    }

    public function test_products_stay_available_with_null_stock(): void
    {
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v2/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.in_stock', null);
    }

    public function test_orders_fail_with_503_and_record_nothing(): void
    {
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser());

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->assertEnvelope($response, 503, success: false);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, OrderReservation::count());
    }

    public function test_orders_return_503_even_before_the_new_table_exists(): void
    {
        Schema::drop('order_reservations');
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser());

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(503);
    }

    public function test_stock_changes_fail_with_503(): void
    {
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser('admin'));

        $this->patchJson("/api/v1/inventory/{$product->id}", ['stock' => 5])->assertStatus(503);

        $this->postJson('/api/v1/products', [
            'name' => 'Not created',
            'price' => 1,
            'category_id' => $this->makeCategory()->id,
            'stock' => 1,
        ])->assertStatus(503);
        $this->assertDatabaseMissing('products', ['name' => 'Not created']);
    }
}
