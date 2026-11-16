<?php

namespace Tests\Feature\Requirement1;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Stock as seen through the API. Every quantity comes from the Warehouse; Laravel
 * has no stock column and no endpoint that changes stock.
 */
class StockContractTest extends ApiTestCase
{
    public function test_laravel_has_no_stock_column_or_stock_tables(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'stock'));
        $this->assertFalse(Schema::hasTable('stock_movements'));
        $this->assertFalse(Schema::hasColumn('order_reservations', 'items'));
    }

    public function test_no_endpoint_can_change_stock(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());

        $this->assertFalse($uris->contains(fn ($uri) => str_contains($uri, 'inventory')));
    }

    public function test_an_order_deducts_stock_in_the_warehouse(): void
    {
        $product = $this->makeProduct(['stock' => 5]);
        $this->actingAsApi($this->makeUser());

        $this->postOrder([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $this->assertSame(3, $this->warehouse->stock[$product->id]);
    }

    public function test_insufficient_stock_returns_409_lists_every_short_item_and_changes_nothing(): void
    {
        $enough = $this->makeProduct(['stock' => 5]);
        $short = $this->makeProduct(['stock' => 1, 'name' => 'Scarce']);
        $empty = $this->makeProduct(['stock' => 0, 'name' => 'Sold out']);
        $this->actingAsApi($this->makeUser());

        $response = $this->postOrder([
            'items' => [
                ['product_id' => $enough->id, 'quantity' => 2],
                ['product_id' => $short->id, 'quantity' => 2],
                ['product_id' => $empty->id, 'quantity' => 3],
            ],
        ]);

        $this->assertEnvelope($response, 409, success: false);
        $response->assertJsonPath('message', 'Insufficient stock for the requested products.')
            ->assertJsonPath('data.items', [
                ['product_id' => $short->id, 'name' => 'Scarce', 'requested_quantity' => 2, 'available_quantity' => 1, 'stocked' => true],
                ['product_id' => $empty->id, 'name' => 'Sold out', 'requested_quantity' => 3, 'available_quantity' => 0, 'stocked' => true],
            ]);
        $this->assertSame([5, 1, 0], [
            $this->warehouse->stock[$enough->id],
            $this->warehouse->stock[$short->id],
            $this->warehouse->stock[$empty->id],
        ]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_requested_quantity_is_sent_unchanged_and_never_clamped(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $this->actingAsApi($this->makeUser());

        $response = $this->postOrder([
            'items' => [['product_id' => $product->id, 'quantity' => 20]],
        ]);

        $this->assertEnvelope($response, 409, success: false);
        $response->assertJsonPath('message', 'Insufficient stock for the requested product.')
            ->assertJsonPath('data.items.0.requested_quantity', 20)
            ->assertJsonPath('data.items.0.available_quantity', 10);
        $this->assertSame([$product->id => 20], $this->warehouse->callsTo('reserve')[0]['args'][1]);
        $this->assertSame(10, $this->warehouse->stock[$product->id]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_products_expose_stock_status_and_in_stock(): void
    {
        $inStock = $this->makeProduct(['stock' => 4]);
        $soldOut = $this->makeProduct(['stock' => 0]);
        $notStocked = $this->makeProduct();
        unset($this->warehouse->stock[$notStocked->id]);
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v1/products/{$inStock->id}")
            ->assertJsonPath('data.stock', 4)
            ->assertJsonPath('data.stock_status', 'in_stock');
        $this->getJson("/api/v2/products/{$inStock->id}")
            ->assertJsonPath('data.stock', 4)
            ->assertJsonPath('data.in_stock', true);
        $this->getJson("/api/v2/products/{$soldOut->id}")
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.stock_status', 'out_of_stock')
            ->assertJsonPath('data.in_stock', false);
        $this->getJson("/api/v2/products/{$notStocked->id}")
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.stock_status', 'not_stocked')
            ->assertJsonPath('data.in_stock', false);
    }

    public function test_v2_in_stock_filter_excludes_sold_out_products(): void
    {
        $inStock = $this->makeProduct(['stock' => 1]);
        $this->makeProduct(['stock' => 0]);
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson('/api/v2/products?in_stock=1')->assertOk();

        $this->assertSame([$inStock->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('pagination.total', 1);
    }

    public function test_a_new_product_is_not_stocked_until_the_warehouse_adds_it(): void
    {
        $category = $this->makeCategory();
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/products', [
            'name' => 'New arrival',
            'price' => 5,
            'category_id' => $category->id,
        ]);

        $this->assertEnvelope($response, 201);
        $response->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.stock_status', 'not_stocked');
        $this->assertArrayNotHasKey($response->json('data.id'), $this->warehouse->stock);
        $this->assertSame(['getStock'], array_column($this->warehouse->calls, 'method'));
    }

    public function test_a_stock_field_sent_with_a_new_product_is_ignored(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/products', [
            'name' => 'Ignored stock',
            'price' => 5,
            'category_id' => $this->makeCategory()->id,
            'stock' => 99,
        ]);

        $this->assertEnvelope($response, 201);
        $this->assertArrayNotHasKey($response->json('data.id'), $this->warehouse->stock);
    }
}
