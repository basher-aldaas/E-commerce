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
            'stock' => 20,
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
            ->assertJsonStructure(['data' => ['id', 'category_id', 'name', 'description', 'price', 'stock', 'is_active', 'image', 'image_url', 'category']]);
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
        $response->assertJsonStructure(['data' => ['id', 'name', 'price', 'stock', 'in_stock', 'is_active', 'image_url', 'category' => ['id', 'name']]])
            ->assertJsonMissingPath('data.image');
    }

    public function test_v2_rejects_invalid_filters(): void
    {
        $this->actingAsApi($this->makeUser());

        $this->assertEnvelope($this->getJson('/api/v2/products?per_page=1000'), 422, success: false);
    }
}
