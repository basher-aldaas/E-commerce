<?php

namespace Tests\Feature\Requirement1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Users\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Users\Models\User;
use RuntimeException;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * RefreshDatabase drops every table on its connection, so refuse to start
     * unless that connection is the throwaway in-memory database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Refusing to run: tests must use in-memory SQLite, not {$connection}/{$database}."
            );
        }
    }

    protected function makeUser(string $role = 'user', array $attributes = []): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::create($attributes + [
            'name' => "Test {$role} {$sequence}",
            'email' => "{$role}{$sequence}@example.test",
            'password' => 'password123',
        ]);

        $user->assignRole($role);

        return $user;
    }

    /** Send later requests with a real JWT, using a fresh guard for each user. */
    protected function actingAsApi(User $user): static
    {
        $token = auth('api')->tokenById($user->getKey());
        $this->resetAuthState();

        return $this->withToken($token);
    }

    protected function asGuest(): static
    {
        $this->resetAuthState();

        return $this->withoutToken();
    }

    /**
     * Forget the guard's user and the JWT's parsed token. A real request starts
     * clean, but tests share one application, so the next request would otherwise
     * act as the previous user.
     */
    protected function resetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
    }

    protected function makeProduct(array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create($attributes + [
            'category_id' => $attributes['category_id'] ?? $this->makeCategory()->id,
            'name' => "Product {$sequence}",
            'description' => 'Test product',
            'price' => 10.00,
            'stock' => 10,
            'is_active' => true,
        ]);
    }

    protected function makeCategory(?string $name = null): Category
    {
        static $sequence = 0;
        $sequence++;

        return Category::create(['name' => $name ?? "Category {$sequence}"]);
    }

    protected function makeOrder(User $owner, string $status = 'pending'): Order
    {
        return Order::create([
            'user_id' => $owner->id,
            'status' => $status,
            'total_amount' => 10,
        ]);
    }

    /** Assert the project's standard success or error JSON envelope. */
    protected function assertEnvelope(TestResponse $response, int $code, bool $success = true): void
    {
        $response->assertStatus($code)
            ->assertJsonStructure(['status', 'data', 'message', 'status_code', 'timestamp'])
            ->assertJsonPath('status', $success ? 1 : 0)
            ->assertJsonPath('status_code', $code);
    }
}
