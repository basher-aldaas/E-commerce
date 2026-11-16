<?php

namespace Tests\Feature\Requirement1;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductsTest extends ApiTestCase
{
    private function productPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Wireless Mouse',
            'description' => 'A mouse',
            'price' => 50,
            'category_id' => $this->makeCategory()->id,
        ];
    }

    public function test_user_can_list_and_view_products_in_v1(): void
    {
        $product = $this->makeProduct(['name' => 'Listed']);
        $this->actingAsApi($this->makeUser());

        $list = $this->getJson('/api/v1/products');
        $this->assertEnvelope($list, 200);
        $list->assertJsonStructure(['pagination' => ['current_page', 'per_page', 'total', 'last_page']]);

        $show = $this->getJson("/api/v1/products/{$product->id}");
        $this->assertEnvelope($show, 200);
        $show->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Listed')
            ->assertJsonStructure(['data' => ['id', 'category_id', 'name', 'description', 'price', 'stock', 'stock_status', 'is_active', 'image', 'image_url', 'category']]);
    }

    public function test_unknown_product_returns_404(): void
    {
        $this->actingAsApi($this->makeUser());

        $this->assertEnvelope($this->getJson('/api/v1/products/999999'), 404, success: false);
        $this->assertEnvelope($this->getJson('/api/v2/products/999999'), 404, success: false);
    }

    public function test_user_cannot_create_a_product(): void
    {
        $payload = $this->productPayload();
        $this->actingAsApi($this->makeUser());

        $this->assertEnvelope($this->postJson('/api/v1/products', $payload), 403, success: false);
        $this->assertDatabaseMissing('products', ['name' => 'Wireless Mouse']);
    }

    public function test_admin_can_create_a_product_with_an_image(): void
    {
        Storage::fake('public');
        $payload = $this->productPayload(['image' => UploadedFile::fake()->image('mouse.png')]);
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->post('/api/v1/products', $payload, ['Accept' => 'application/json']);

        $this->assertEnvelope($response, 201);
        $response->assertJsonPath('data.name', 'Wireless Mouse')
            ->assertJsonPath('data.is_active', true);
        Storage::disk('public')->assertExists($response->json('data.image'));
    }

    public function test_product_validation_returns_422(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->postJson('/api/v1/products', ['name' => '']);

        $this->assertEnvelope($response, 422, success: false);
        $this->assertArrayHasKey('price', $response->json('data'));
        $this->assertArrayHasKey('category_id', $response->json('data'));
    }

    public function test_only_admin_can_create_categories_and_both_roles_can_list(): void
    {
        $this->actingAsApi($this->makeUser());
        $this->assertEnvelope($this->postJson('/api/v1/categories', ['name' => 'Denied']), 403, success: false);
        $this->assertEnvelope($this->getJson('/api/v1/categories'), 200);

        $this->actingAsApi($this->makeUser('admin'));
        $this->assertEnvelope($this->postJson('/api/v1/categories', ['name' => 'Allowed']), 201);
        $this->assertDatabaseHas('categories', ['name' => 'Allowed']);
    }

    public function test_v2_lists_active_products_only_with_filters(): void
    {
        $books = $this->makeCategory('Books');
        $toys = $this->makeCategory('Toys');
        $match = $this->makeProduct(['name' => 'Blue Book', 'category_id' => $books->id, 'price' => 30]);
        $this->makeProduct(['name' => 'Blue Toy', 'category_id' => $toys->id, 'price' => 30]);
        $this->makeProduct(['name' => 'Blue Hidden', 'category_id' => $books->id, 'price' => 30, 'is_active' => false]);
        $this->makeProduct(['name' => 'Blue Costly', 'category_id' => $books->id, 'price' => 300]);
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson("/api/v2/products?search=Blue&category_id={$books->id}&min_price=10&max_price=100");

        $this->assertEnvelope($response, 200);
        $this->assertSame([$match->id], array_column($response->json('data'), 'id'));
    }

    public function test_v2_shape_differs_from_v1(): void
    {
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson("/api/v2/products/{$product->id}");

        $this->assertEnvelope($response, 200);
        $response->assertJsonStructure(['data' => ['id', 'name', 'price', 'stock', 'stock_status', 'in_stock', 'is_active', 'image_url', 'category' => ['id', 'name']]])
            ->assertJsonMissingPath('data.image');
    }

    public function test_admin_can_update_a_product(): void
    {
        $product = $this->makeProduct(['name' => 'Old name', 'price' => 10]);
        $category = $this->makeCategory('Garden');
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->putJson("/api/v1/products/{$product->id}", [
            'name' => 'New name',
            'description' => 'Updated',
            'price' => 12.5,
            'category_id' => $category->id,
        ]);

        $this->assertEnvelope($response, 200);
        $response->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.price', '12.50')
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.stock', 10);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'New name', 'description' => 'Updated', 'category_id' => $category->id]);
    }

    public function test_only_an_admin_can_update_a_product(): void
    {
        $product = $this->makeProduct(['name' => 'Unchanged']);
        $payload = $this->productPayload(['name' => 'Hijacked']);

        $this->actingAsApi($this->makeUser());
        $this->assertEnvelope($this->putJson("/api/v1/products/{$product->id}", $payload), 403, success: false);

        $this->asGuest();
        $this->assertEnvelope($this->putJson("/api/v1/products/{$product->id}", $payload), 401, success: false);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Unchanged']);
    }

    public function test_updating_an_unknown_product_returns_404(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $this->assertEnvelope($this->putJson('/api/v1/products/999999', $this->productPayload()), 404, success: false);
    }

    public function test_product_update_validation_returns_422(): void
    {
        $product = $this->makeProduct(['name' => 'Unchanged']);
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->putJson("/api/v1/products/{$product->id}", ['name' => '', 'price' => -1, 'category_id' => 999999, 'is_active' => 'maybe']);

        $this->assertEnvelope($response, 422, success: false);
        foreach (['name', 'price', 'category_id', 'is_active'] as $field) {
            $this->assertArrayHasKey($field, $response->json('data'), "no error for {$field}");
        }
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Unchanged']);
    }

    public function test_an_update_can_hide_a_product_from_the_store(): void
    {
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser('admin'));

        $this->putJson("/api/v1/products/{$product->id}", $this->productPayload(['is_active' => false]))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAsApi($this->makeUser());
        $this->assertSame([], $this->getJson('/api/v2/products')->json('data'));
    }

    /** Files can't be sent with a real PUT in PHP, so the image uses POST + _method=PUT. */
    public function test_admin_can_replace_the_image_with_method_override(): void
    {
        Storage::fake('public');
        $product = $this->makeProduct();
        $this->actingAsApi($this->makeUser('admin'));

        $response = $this->post("/api/v1/products/{$product->id}", $this->productPayload([
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('new.png'),
        ]), ['Accept' => 'application/json']);

        $this->assertEnvelope($response, 200);
        Storage::disk('public')->assertExists($response->json('data.image'));
    }

    public function test_an_update_without_an_image_keeps_the_current_one(): void
    {
        $product = $this->makeProduct(['image' => 'products/current.png']);
        $this->actingAsApi($this->makeUser('admin'));

        $this->putJson("/api/v1/products/{$product->id}", $this->productPayload())
            ->assertOk()
            ->assertJsonPath('data.image', 'products/current.png');
    }

    public function test_v1_lists_can_be_filtered_by_category_and_page_size(): void
    {
        $books = $this->makeCategory('Books');
        $toys = $this->makeCategory('Toys');
        $first = $this->makeProduct(['category_id' => $books->id]);
        $second = $this->makeProduct(['category_id' => $books->id]);
        $this->makeProduct(['category_id' => $toys->id]);
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson("/api/v1/products?category_id={$books->id}&per_page=1&page=2");

        $this->assertEnvelope($response, 200);
        $this->assertSame([$first->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 2);
        $this->assertNotNull($second);
    }

    public function test_v1_rejects_invalid_filters(): void
    {
        $this->actingAsApi($this->makeUser());

        foreach (['per_page=1000', 'per_page=0', 'category_id=abc', 'page=0'] as $query) {
            $this->assertEnvelope($this->getJson("/api/v1/products?{$query}"), 422, success: false);
        }
    }

    public function test_v2_rejects_invalid_filters(): void
    {
        $this->actingAsApi($this->makeUser());

        $this->assertEnvelope($this->getJson('/api/v2/products?per_page=1000'), 422, success: false);
    }
}
