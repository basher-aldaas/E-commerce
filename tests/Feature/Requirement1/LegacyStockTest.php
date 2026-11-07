<?php

namespace Tests\Feature\Requirement1;

/**
 * Pins how stock behaves while Laravel still owns it.
 *
 * Requirement 2 moves stock to the Warehouse service, so these are the tests
 * expected to change. The public API contract they check (status codes, the
 * `stock` and `in_stock` fields) should survive; only the data source moves.
 */
class LegacyStockTest extends ApiTestCase
{
    public function test_order_deducts_stock_from_the_products_table(): void
    {
        $product = $this->makeProduct(['stock' => 5]);
        $this->actingAsApi($this->makeUser());

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_insufficient_stock_returns_409_and_changes_nothing(): void
    {
        $enough = $this->makeProduct(['stock' => 5]);
        $short = $this->makeProduct(['stock' => 1]);
        $this->actingAsApi($this->makeUser());

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $enough->id, 'quantity' => 2],
                ['product_id' => $short->id, 'quantity' => 2],
            ],
        ]);

        $this->assertEnvelope($response, 409, success: false);
        $response->assertJsonPath('data.product_id', $short->id)
            ->assertJsonPath('data.available', 1)
            ->assertJsonPath('data.requested', 2);
        $this->assertSame(5, $enough->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_products_expose_stock_and_in_stock(): void
    {
        $inStock = $this->makeProduct(['stock' => 4]);
        $soldOut = $this->makeProduct(['stock' => 0]);
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v1/products/{$inStock->id}")->assertJsonPath('data.stock', 4);
        $this->getJson("/api/v2/products/{$inStock->id}")
            ->assertJsonPath('data.stock', 4)
            ->assertJsonPath('data.in_stock', true);
        $this->getJson("/api/v2/products/{$soldOut->id}")->assertJsonPath('data.in_stock', false);
    }

    public function test_v2_in_stock_filter_excludes_sold_out_products(): void
    {
        $inStock = $this->makeProduct(['stock' => 1]);
        $this->makeProduct(['stock' => 0]);
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson('/api/v2/products?in_stock=1')->assertOk();

        $this->assertSame([$inStock->id], array_column($response->json('data'), 'id'));
    }

    public function test_only_admin_can_update_inventory(): void
    {
        $product = $this->makeProduct(['stock' => 1]);

        $this->actingAsApi($this->makeUser());
        $this->assertEnvelope($this->patchJson("/api/v1/inventory/{$product->id}", ['stock' => 99]), 403, success: false);
        $this->assertSame(1, $product->fresh()->stock);

        $this->actingAsApi($this->makeUser('admin'));
        $this->assertEnvelope($this->patchJson("/api/v1/inventory/{$product->id}", ['stock' => 99]), 200);
        $this->assertSame(99, $product->fresh()->stock);
    }

    public function test_inventory_rejects_negative_stock(): void
    {
        $product = $this->makeProduct(['stock' => 1]);
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope($this->patchJson("/api/v1/inventory/{$product->id}", ['stock' => -1]), 422, success: false);
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_admin_sets_stock_when_creating_a_product(): void
    {
        $category = $this->makeCategory();
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/products', [
            'name' => 'Stocked',
            'price' => 5,
            'category_id' => $category->id,
            'stock' => 12,
        ]);

        $this->assertEnvelope($response, 201);
        $response->assertJsonPath('data.stock', 12);
    }
}
