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
 * The whole application over HTTP, against the real Python service and its own
 * MySQL database, with Laravel's data in E-commerce. See UsesWarehouseTestServer
 * for how the Warehouse database is selected.
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

        $orderId = $this->postOrder($this->orderBody($product, 4))
            ->assertStatus(201)
            ->json('data.id');

        $key = OrderReservation::sole()->reservation_key;
        $this->assertSame(6, $this->quantity($product->id));
        $this->assertSame('RESERVED', $this->reservationStatus($key));

        $this->actingAsApi($this->makeUser('admin'));
        $this->postJson("/api/v1/orders/{$orderId}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $this->quantity($product->id));
        $this->assertSame('RELEASED', $this->reservationStatus($key));
        $this->assertSame(OrderReservation::CANCELLED, OrderReservation::sole()->status);
    }

    public function test_insufficient_stock_is_refused_with_real_details(): void
    {
        $product = $this->product(1);
        $this->actingAsApi($this->makeUser());

        $this->postOrder($this->orderBody($product, 3))
            ->assertStatus(409)
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.available_quantity', 1)
            ->assertJsonPath('data.items.0.requested_quantity', 3);

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

        $this->postOrder($this->orderBody($product, 4))->assertStatus(500);

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
        $first = $this->postOrder($this->orderBody($product, 2), $headers)->assertStatus(201);
        $this->actingAsApi($user);
        $second = $this->postOrder($this->orderBody($product, 2), $headers)->assertStatus(201);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(8, $this->quantity($product->id));
    }

    public function test_a_requested_quantity_above_stock_is_refused_whole(): void
    {
        $product = $this->product(10);
        $this->actingAsApi($this->makeUser());

        $this->postOrder($this->orderBody($product, 20))
            ->assertStatus(409)
            ->assertJsonPath('data.items.0.requested_quantity', 20)
            ->assertJsonPath('data.items.0.available_quantity', 10);

        $this->assertSame(10, $this->quantity($product->id));
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(OrderReservation::REJECTED, OrderReservation::sole()->status);
    }

    public function test_a_new_product_is_not_stocked_until_the_warehouse_operator_adds_stock(): void
    {
        $this->actingAsApi($this->makeUser('admin'));

        $id = $this->postJson('/api/v1/products', [
            'name' => 'Real stock',
            'price' => 5,
            'category_id' => $this->makeCategory()->id,
        ])->assertStatus(201)
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.stock_status', 'not_stocked')
            ->json('data.id');
        $this->assertNull($this->quantity($id));

        // What `python -m app.cli set-stock <id> 12` does on the Warehouse side.
        $this->seedStock([$id => 12]);

        $this->getJson("/api/v1/products/{$id}")
            ->assertJsonPath('data.stock', 12)
            ->assertJsonPath('data.stock_status', 'in_stock');
    }

    public function test_a_stopped_warehouse_keeps_pages_up_and_fails_orders_safely(): void
    {
        $product = $this->product(10);
        $this->app->instance(
            WarehouseGateway::class,
            $this->realGateway(readTimeoutMs: 5000, writeTimeoutMs: 5000, releaseTimeoutMs: 5000, connectTimeoutMs: 500, port: $this->freePort())
        );
        $this->actingAsApi($this->makeUser());

        $this->getJson("/api/v2/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.stock_status', 'unavailable')
            ->assertJsonPath('data.in_stock', null);

        // A connection failure is a fast 503, even with 5 s call deadlines: only the
        // 500 ms connect wait is spent, and nothing was sent, so nothing is released.
        $started = microtime(true);
        $this->postOrder($this->orderBody($product, 1))->assertStatus(503);
        $this->assertLessThan(1.5, microtime(true) - $started);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->quantity($product->id));
        $this->assertSame(OrderReservation::REJECTED, OrderReservation::sole()->status);
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
        $this->app->instance(WarehouseGateway::class, $this->realGateway(writeTimeoutMs: 300, releaseTimeoutMs: 300));
        $this->actingAsApi($this->makeUser());

        $this->whileStockRowIsLocked($product->id, function () use ($product) {
            $this->postOrder($this->orderBody($product, 4))->assertStatus(504);
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
        $outcomes = $this->raceOrders(stock: 1, quantity: 1, processes: 20, sameKey: false);

        $statuses = array_count_values(array_column($outcomes['results'], 'status'));
        ksort($statuses);

        $this->assertSame([201 => 1, 409 => 19], $statuses, json_encode($outcomes['results']));
        $this->assertSame(1, $outcomes['orders']);
        $this->assertSame(0, $this->quantity($outcomes['product_id']));
        $this->assertSame(1, (int) $this->database->scalar("SELECT COUNT(*) FROM reservations WHERE status = 'RESERVED'"));
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

    /**
     * Stalled order requests (Reserve done, order not saved yet) and three
     * separate warehouse:reconcile processes, all started at the same instant
     * against the real Warehouse. Only reconcile works on them here.
     */
    public function test_separate_reconcile_processes_release_stalled_reservations_exactly_once(): void
    {
        $race = $this->raceReconcile(stalled: 5, reconcilers: 3, wakeStalledRequests: false);

        foreach ($race['reconcilers'] as $run) {
            $this->assertSame(0, $run['exit'] ?? null, json_encode($race));
        }
        foreach ($race['reservations'] as $reservation) {
            $this->assertSame(OrderReservation::RELEASED, $reservation['status'], json_encode($race));
            $this->assertSame('RELEASED', $this->reservationStatus($reservation['reservation_key']));
        }
        // 5 × 2 units were held; each was given back once, never twice.
        $this->assertSame(20, $this->quantity($race['product_id']), json_encode($race));
        $this->assertSame(0, $race['orders']);
    }

    /**
     * The same stalled requests now wake up and try to save their orders while
     * three reconcile processes release them. Whoever wins, every reservation must
     * end in one of the two valid outcomes, and the real stock must match.
     */
    public function test_reconcile_and_waking_requests_racing_never_release_a_placed_order(): void
    {
        $race = $this->raceReconcile(stalled: 8, reconcilers: 3, wakeStalledRequests: true);

        $placed = 0;
        foreach ($race['reservations'] as $index => $reservation) {
            $woke = $race['woken'][$index];
            $warehouse = $this->reservationStatus($reservation['reservation_key']);

            if ($reservation['status'] === OrderReservation::PLACED) {
                $placed++;
                $this->assertTrue($woke['saved'] ?? false, json_encode($race));
                $this->assertContains($reservation['order_id'], $race['order_ids']);
                $this->assertSame('RESERVED', $warehouse, 'a placed order lost its stock: '.json_encode($race));
            } else {
                $this->assertSame(OrderReservation::RELEASED, $reservation['status'], json_encode($race));
                $this->assertFalse($woke['saved'] ?? true, json_encode($race));
                $this->assertNull($reservation['order_id']);
                $this->assertSame('RELEASED', $warehouse);
            }
        }

        $this->assertSame($placed, $race['orders'], json_encode($race));
        $this->assertSame(32 - 2 * $placed, $this->quantity($race['product_id']), json_encode($race));

        // Who won varies from run to run; RACE_REPORT=<file> records it as evidence.
        if ($report = env('RACE_REPORT')) {
            file_put_contents($report, json_encode($race + ['placed' => $placed, 'released' => count($race['reservations']) - $placed, 'stock_after' => $this->quantity($race['product_id'])])."\n", FILE_APPEND);
        }
    }

    /**
     * @return array{product_id: int, reconcilers: list<array>, woken: list<array>, reservations: list<array>, orders: int, order_ids: list<int>}
     */
    private function raceReconcile(int $stalled, int $reconcilers, bool $wakeStalledRequests): array
    {
        $ids = $this->fixture('setup', (string) $stalled)->mustRun()->getOutput();
        $ids = json_decode(trim($ids), true, flags: JSON_THROW_ON_ERROR);

        try {
            $this->seedStock([$ids['product_id'] => 4 * $stalled]);

            $reservationIds = [];
            foreach ($ids['user_ids'] as $userId) {
                // Not mustRun(): see the exit-code note below.
                $stall = $this->fixture('stall', (string) $userId, (string) $ids['product_id'], '2');
                $stall->start();
                $result = $this->resultOf($stall);
                $this->assertArrayHasKey('reservation_id', $result, 'stall failed: '.json_encode($result));
                $reservationIds[] = $result['reservation_id'];
            }
            $this->assertSame(2 * $stalled, $this->quantity($ids['product_id']), 'setup: '.json_encode([
                'stall_outputs' => $reservationIds,
                'warehouse' => $this->database->select('SELECT r.reservation_key, r.status, i.product_id, i.quantity FROM reservations r LEFT JOIN reservation_items i ON i.reservation_id = r.id'),
                'stock' => $this->database->select('SELECT * FROM stock'),
            ]));

            $wakeAt = (string) (microtime(true) + 8);
            $runs = [];
            for ($i = 0; $i < $reconcilers; $i++) {
                $runs[] = $this->fixture('reconcile', $wakeAt);
            }
            $woken = [];
            if ($wakeStalledRequests) {
                // Spread the wake-ups over the ~0.2-0.8 s the reconcile runs take, so
                // some requests save first and some find their reservation claimed.
                foreach ($reservationIds as $index => $reservationId) {
                    $at = (string) ((float) $wakeAt + $index * 0.1);
                    $woken[] = $this->fixture('wake', (string) $reservationId, (string) $ids['product_id'], '2', $at);
                }
            }
            foreach ([...$runs, ...$woken] as $process) {
                $process->start();
            }

            $outcome = fn (Process $process) => $this->resultOf($process);

            return [
                'product_id' => $ids['product_id'],
                'reconcilers' => array_map($outcome, $runs),
                'woken' => array_map($outcome, $woken),
                'reservations' => json_decode($this->fixture('reservations', json_encode($ids['user_ids']))->mustRun()->getOutput(), true),
                'orders' => (int) trim($this->fixture('orders', (string) $ids['product_id'])->mustRun()->getOutput()),
                'order_ids' => json_decode($this->fixture('order-ids', (string) $ids['product_id'])->mustRun()->getOutput(), true),
            ];
        } finally {
            $this->fixture('cleanup', json_encode($ids))->run();
        }
    }

    /**
     * Waits for a started fixture process to print its JSON result, which it does
     * only after its work is committed. Judged by that result, not the exit code:
     * on this Windows host the grpc extension sometimes crashes (0xC0000005) or
     * hangs while PHP shuts down, after the result is out. Both are kept as data;
     * a process still running after printing is stopped.
     *
     * @return array<string, mixed>
     */
    private function resultOf(Process $process): array
    {
        $deadline = microtime(true) + 60;
        while ($process->isRunning() && json_decode(trim($process->getOutput()), true) === null && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $result = json_decode(trim($process->getOutput()), true);
        $hungAfterResult = $process->isRunning();
        if ($hungAfterResult) {
            $process->stop(0);
        }

        if (! is_array($result)) {
            return ['crash' => $process->getErrorOutput().$process->getOutput(), 'exit_code' => $process->getExitCode()];
        }

        return $result + ['exit_code' => $hungAfterResult ? 'hung after printing its result' : $process->getExitCode()];
    }

    /** One run of tests/Integration/fixtures/race_order.php in its own PHP process. */
    private function fixture(string ...$arguments): Process
    {
        $php = (new PhpExecutableFinder())->find();

        return new Process([$php, base_path('tests/Integration/fixtures/race_order.php'), ...$arguments], base_path(), $this->fixtureEnvironment(), null, 120);
    }

    /** @return array<string, string> */
    private function fixtureEnvironment(): array
    {
        $mysql = config('database.connections.mysql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $mysql['host'],
            'DB_PORT' => (string) $mysql['port'],
            'DB_DATABASE' => (string) $mysql['database'],
            'DB_USERNAME' => (string) $mysql['username'],
            'DB_PASSWORD' => (string) $mysql['password'],
            'DB_URL' => '',
            'WAREHOUSE_GRPC_ENABLED' => 'true',
            'WAREHOUSE_GRPC_TARGET' => '127.0.0.1:'.self::$port,
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
        ];
    }

    /**
     * Runs real concurrent orders, each in its own PHP process with its own
     * connection to E-commerce. The racers can't see this test's uncommitted
     * transaction, so the fixture commits its own rows and removes them again.
     */
    private function raceOrders(int $stock, int $quantity, int $processes, bool $sameKey): array
    {
        $setup = $this->fixture('setup', (string) $processes)->mustRun();
        $ids = json_decode(trim($setup->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        try {
            $this->seedStock([$ids['product_id'] => $stock]);

            $wakeAt = (string) (microtime(true) + 6);
            $racers = [];
            foreach ($ids['user_ids'] as $index => $userId) {
                $racers[] = $racer = $this->fixture('place', (string) ($sameKey ? $ids['user_ids'][0] : $userId), (string) $ids['product_id'], (string) $quantity, $wakeAt, $sameKey ? 'shared' : "key-{$index}");
                $racer->start();
            }

            $results = [];
            foreach ($racers as $racer) {
                $racer->wait();
                $results[] = json_decode(trim($racer->getOutput()), true) ?? ['status' => 'crash', 'error' => $racer->getErrorOutput()];
            }

            return [
                'results' => $results,
                'orders' => (int) trim($this->fixture('orders', (string) $ids['product_id'])->mustRun()->getOutput()),
                'product_id' => $ids['product_id'],
            ];
        } finally {
            $this->fixture('cleanup', json_encode($ids))->run();
        }
    }
}
