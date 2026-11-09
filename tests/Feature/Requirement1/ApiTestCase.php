<?php

namespace Tests\Feature\Requirement1;

use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Users\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Users\Models\User;
use RuntimeException;
use Tests\Support\FakeWarehouseGateway;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeWarehouseGateway $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->warehouse = new FakeWarehouseGateway();
        $this->app->instance(WarehouseGateway::class, $this->warehouse);
    }

    /**
     * RefreshDatabase drops every table on its connection. Tests are meant to
     * run on the app's MySQL database E-commerce (see phpunit.xml), so refuse
     * any other target, such as the Warehouse database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'mysql' || $database !== 'E-commerce') {
            throw new RuntimeException(
                "Refusing to run: tests must use the MySQL database E-commerce, not {$connection}/{$database}."
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

    /** The `stock` attribute goes to the fake Warehouse, not to Laravel. */
    protected function makeProduct(array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;

        $stock = $attributes['stock'] ?? 10;
        unset($attributes['stock']);

        $product = Product::create($attributes + [
            'category_id' => $attributes['category_id'] ?? $this->makeCategory()->id,
            'name' => "Product {$sequence}",
            'description' => 'Test product',
            'price' => 10.00,
            'is_active' => true,
        ]);

        $this->warehouse->stock[$product->id] = $stock;

        return $product;
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
