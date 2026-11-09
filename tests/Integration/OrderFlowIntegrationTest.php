<?php

namespace Tests\Integration;

use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Models\Product;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * The whole application over HTTP, against the real Python service and
 * warehouse_test. Laravel's own data stays in throwaway SQLite.
 */
class OrderFlowIntegrationTest extends ApiTestCase
{
    use UsesWarehouseTestServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareWarehouseTestServer();
        $this->app->instance(WarehouseGateway::class, $this->realGateway());
    }

    private function product(int $stock): Product
    {
        $product = $this->makeProduct();
        unset($this->warehouse->stock[$product->id]);
        $this->seedStock([$product->id => $stock]);

        return $product;
    }

    private function orderBody(Product $product, int $quantity): array
    {
        return ['items' => [['product_id' => $product->id, 'quantity' => $quantity]]];
    }

    public function test_product_pages_show_the_real_warehouse_quantity(): void
    {
        $product = $this->product(7);
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('data.stock', 7);
        $this->getJson("/api/v2/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', 7)
            ->assertJsonPath('data.in_stock', true);
    }

    public function test_placing_and_cancelling_an_order(): void
    {
        $product = $this->product(10);
        $this->actingAsApi($this->makeUser());

        $orderId = $this->postJson('/api/v1/orders', $this->orderBody($product, 4))
            ->assertStatus(201)
            ->json('data.id');

        $key = OrderReservation::sole()->reservation_key;
        $this->assertSame(6, $this->quantity($product->id));
        $this->assertSame('CONFIRMED', $this->reservationStatus($key));

        $this->actingAsApi($this->makeUser('admin'));
        $this->postJson("/api/v1/orders/{$orderId}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $this->quantity($product->id));
        $this->assertSame('RETURNED', $this->reservationStatus($key));
    }

    public function test_insufficient_stock_is_refused_with_real_details(): void
    {
        $product = $this->product(1);
        $this->actingAsApi($this->makeUser());

        $this->postJson('/api/v1/orders', $this->orderBody($product, 3))
            ->assertStatus(409)
            ->assertJsonPath('data.available', 1)
            ->assertJsonPath('data.requested', 3);

        $this->assertSame(1, $this->quantity($product->id));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_failed_order_save_returns_the_real_stock(): void
    {
        $product = $this->product(10);
        Event::listen('eloquent.creating: '.Order::class, function () {
            throw new RuntimeException('database failure');
        });
        $this->actingAsApi($this->makeUser());

        $this->postJson('/api/v1/orders', $this->orderBody($product, 4))->assertStatus(500);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->quantity($product->id));
        $this->assertSame('RELEASED', $this->reservationStatus(OrderReservation::sole()->reservation_key));
    }

    public function test_repeating_an_idempotency_key_orders_once(): void
    {
        $product = $this->product(10);
        $user = $this->makeUser();
        $headers = ['Idempotency-Key' => 'cart-1'];

        $this->actingAsApi($user);
        $first = $this->postJson('/api/v1/orders', $this->orderBody($product, 2), $headers)->assertStatus(201);
        $this->actingAsApi($user);
        $second = $this->postJson('/api/v1/orders', $this->orderBody($product, 2), $headers)->assertStatus(201);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(8, $this->quantity($product->id));
    }

    public function test_new_products_and_admin_updates_go_through_the_real_service(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $id = $this->postJson('/api/v1/products', [
            'name' => 'Real stock',
            'price' => 5,
            'category_id' => $this->makeCategory()->id,
            'stock' => 12,
        ])->assertStatus(201)->assertJsonPath('data.stock', 12)->json('data.id');
        $this->assertSame(12, $this->quantity($id));

        $this->patchJson("/api/v1/inventory/{$id}", ['stock' => 30])
            ->assertOk()
            ->assertJsonPath('data.stock', 30);
        $this->assertSame(30, $this->quantity($id));
    }

    public function test_existing_real_stock_blocks_a_new_product_with_that_id(): void
    {
        $this->seedStock([1 => 8]);
        $category = $this->makeCategory();
        $this->actingAsApi($this->makeUser('admin'));

        $this->postJson('/api/v1/products', [
            'name' => 'Collides',
            'price' => 5,
            'category_id' => $category->id,
            'stock' => 50,
        ])->assertStatus(409);

        $this->assertSame(8, $this->quantity(1));
        $this->assertDatabaseMissing('products', ['name' => 'Collides']);
    }

    public function test_a_stopped_warehouse_keeps_pages_up_and_fails_orders_safely(): void
    {
        $product = $this->product(10);
        $this->app->instance(
            WarehouseGateway::class,
            $this->realGateway(readTimeoutMs: 5000, writeTimeoutMs: 5000, retries: 0, port: $this->freePort())
        );
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v2/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.in_stock', null);

        $this->postJson('/api/v1/orders', $this->orderBody($product, 1))->assertStatus(503);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->quantity($product->id));
        $this->assertSame(OrderReservation::RELEASE_PENDING, OrderReservation::sole()->status);
    }

    public function test_a_real_get_stock_timeout_keeps_product_pages_up_with_null_stock(): void
    {
        $product = $this->product(10);
        $this->app->instance(WarehouseGateway::class, $this->realGateway(readTimeoutMs: 1));
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v2/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.name', $product->name)
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.in_stock', null);

        $this->getJson('/api/v2/products?in_stock=1')->assertStatus(504);
    }

    public function test_a_stalled_warehouse_fails_the_order_and_reconcile_recovers_the_stock(): void
    {
        $product = $this->product(10);
        config(['warehouse.enabled' => true]);
        $this->app->instance(WarehouseGateway::class, $this->realGateway(writeTimeoutMs: 300, retries: 0));
        $this->actingAsApi($this->makeUser());

        $this->whileStockRowIsLocked($product->id, function () use ($product) {
            $this->postJson('/api/v1/orders', $this->orderBody($product, 4))->assertStatus(504);
        });

        $this->assertDatabaseCount('orders', 0);

        $this->app->instance(WarehouseGateway::class, $this->realGateway());
        $this->assertSame(0, Artisan::call('warehouse:reconcile', ['--older-than' => 0]));

        $reservation = OrderReservation::sole();
        $this->assertSame(OrderReservation::RELEASED, $reservation->status);
        $this->assertSame('RELEASED', $this->reservationStatus($reservation->reservation_key));
        $this->assertSame(10, $this->quantity($product->id));
    }

    public function test_separate_processes_racing_for_the_last_unit_sell_it_once(): void
    {
        $outcomes = $this->raceOrders(stock: 1, quantity: 1, processes: 8, sameKey: false);

        $statuses = array_column($outcomes['results'], 'status');
        sort($statuses);

        $this->assertSame([201, 409, 409, 409, 409, 409, 409, 409], $statuses, json_encode($outcomes['results']));
        $this->assertSame(1, $outcomes['orders']);
        $this->assertSame(0, $this->quantity($outcomes['product_id']));
    }

    public function test_separate_processes_racing_with_one_key_order_once(): void
    {
        $outcomes = $this->raceOrders(stock: 10, quantity: 2, processes: 6, sameKey: true);

        foreach ($outcomes['results'] as $result) {
            $this->assertContains($result['status'], [201, 409], json_encode($outcomes['results']));
        }
        $this->assertContains(201, array_column($outcomes['results'], 'status'));
        $this->assertSame(1, $outcomes['orders']);
        $this->assertSame(8, $this->quantity($outcomes['product_id']));
    }

    /** Runs real concurrent orders, each in its own PHP process and SQLite connection. */
    private function raceOrders(int $stock, int $quantity, int $processes, bool $sameKey): array
    {
        $database = tempnam(sys_get_temp_dir(), 'race').'.sqlite';
        touch($database);

        $php = (new PhpExecutableFinder())->find();
        $script = base_path('tests/Integration/fixtures/race_order.php');
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'WAREHOUSE_GRPC_ENABLED' => 'true',
            'WAREHOUSE_GRPC_TARGET' => '127.0.0.1:'.self::$port,
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
        ];

        try {
            $setup = new Process([$php, $script, 'setup', (string) $processes], base_path(), $env, null, 120);
            $setup->mustRun();
            $ids = json_decode(trim($setup->getOutput()), true, flags: JSON_THROW_ON_ERROR);

            $this->seedStock([$ids['product_id'] => $stock]);

            $wakeAt = (string) (microtime(true) + 4);
            $racers = [];
            foreach ($ids['user_ids'] as $index => $userId) {
                $racers[] = $racer = new Process(
                    [$php, $script, 'place', (string) $userId, (string) $ids['product_id'], (string) $quantity, $wakeAt, $sameKey ? 'shared' : "key-{$index}"],
                    base_path(),
                    $env,
                    null,
                    120
                );
                $racer->start();
            }

            $results = [];
            foreach ($racers as $racer) {
                $racer->wait();
                $results[] = json_decode(trim($racer->getOutput()), true) ?? ['status' => 'crash', 'error' => $racer->getErrorOutput()];
            }

            $count = new Process([$php, $script, 'orders'], base_path(), $env, null, 60);
            $count->mustRun();

            return [
                'results' => $results,
                'orders' => (int) trim($count->getOutput()),
                'product_id' => $ids['product_id'],
            ];
        } finally {
            @unlink($database);
            @unlink(substr($database, 0, -strlen('.sqlite')));
        }
    }
}
