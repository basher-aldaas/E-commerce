<?php

namespace Tests\Integration;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\GrpcWarehouseGateway;
use App\Services\Warehouse\WarehouseStub;
use Grpc\ChannelCredentials;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Real PHP client, real Python gRPC server, real MySQL.
 *
 * The test starts its own server on a free port, using the warehouse_test database
 * only. It deletes all rows there, so it refuses any name not ending in _test. It
 * reports a visible skip when the service or database isn't available.
 */
class WarehouseGrpcIntegrationTest extends TestCase
{
    private const TEST_DATABASE = 'warehouse_test';

    private static ?Process $server = null;

    private static int $port = 0;

    private static ?string $skipReason = null;

    private Connection $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (! str_ends_with(self::TEST_DATABASE, '_test')) {
            throw new RuntimeException('Refusing to run: this test deletes all rows in its database.');
        }

        config(['database.connections.warehouse_integration' => array_merge(
            config('database.connections.mysql'),
            ['database' => self::TEST_DATABASE]
        )]);

        try {
            $this->database = DB::connection('warehouse_integration');
            $this->database->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL or '.self::TEST_DATABASE.' is not available: '.$e->getMessage());
        }

        $this->startServerOnce();

        $this->database->delete('DELETE FROM reservation_items');
        $this->database->delete('DELETE FROM reservations');
        $this->database->delete('DELETE FROM stock');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(2);
        self::$server = null;
    }

    private function startServerOnce(): void
    {
        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        if (self::$server !== null) {
            return;
        }

        $servicePath = realpath(env('WAREHOUSE_SERVICE_PATH', base_path('../../warehouse-service'))) ?: '';
        $python = $servicePath.DIRECTORY_SEPARATOR.'venv'.DIRECTORY_SEPARATOR
            .(PHP_OS_FAMILY === 'Windows' ? 'Scripts'.DIRECTORY_SEPARATOR.'python.exe' : 'bin'.DIRECTORY_SEPARATOR.'python');

        if (! is_file($python)) {
            self::$skipReason = 'Warehouse service not found. Set WAREHOUSE_SERVICE_PATH.';
            $this->markTestSkipped(self::$skipReason);
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $mysql = config('database.connections.mysql');

        self::$server = new Process([$python, '-m', 'app.grpc_server'], $servicePath, [
            'WAREHOUSE_DB_HOST' => $mysql['host'],
            'WAREHOUSE_DB_PORT' => (string) $mysql['port'],
            'WAREHOUSE_DB_USER' => $mysql['username'],
            'WAREHOUSE_DB_PASSWORD' => (string) $mysql['password'],
            'WAREHOUSE_DB_NAME' => self::TEST_DATABASE,
            'WAREHOUSE_GRPC_HOST' => '127.0.0.1',
            'WAREHOUSE_GRPC_PORT' => (string) self::$port,
        ]);
        self::$server->setTimeout(null);
        self::$server->start();

        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            try {
                $this->gateway(readTimeoutMs: 500)->getStock([1]);

                return;
            } catch (WarehouseException) {
                if (! self::$server->isRunning()) {
                    break;
                }
                usleep(200_000);
            }
        }

        $output = self::$server->getErrorOutput();
        self::$server->stop(2);
        self::$server = null;

        throw new RuntimeException('The Warehouse test server did not start: '.$output);
    }

    private function gateway(int $readTimeoutMs = 2000, int $writeTimeoutMs = 8000, int $retries = 1): GrpcWarehouseGateway
    {
        return new GrpcWarehouseGateway(
            new WarehouseStub('127.0.0.1:'.self::$port, ['credentials' => ChannelCredentials::createInsecure()]),
            $readTimeoutMs,
            $writeTimeoutMs,
            $retries,
        );
    }

    private function seedStock(array $quantities): void
    {
        foreach ($quantities as $productId => $quantity) {
            $this->database->insert('INSERT INTO stock (product_id, quantity) VALUES (?, ?)', [$productId, $quantity]);
        }
    }

    private function quantity(int $productId): ?int
    {
        return $this->database->selectOne('SELECT quantity FROM stock WHERE product_id = ?', [$productId])?->quantity;
    }

    private function reservationStatus(string $key): ?string
    {
        return $this->database->selectOne('SELECT status FROM reservations WHERE reservation_key = ?', [$key])?->status;
    }

    public function test_get_stock_reads_quantities_and_tracked_flags(): void
    {
        $this->seedStock([1 => 8, 2 => 0]);

        $levels = $this->gateway()->getStock([1, 2, 999]);

        $this->assertSame(8, $levels[1]->quantity);
        $this->assertTrue($levels[1]->tracked);
        $this->assertSame(0, $levels[2]->quantity);
        $this->assertTrue($levels[2]->tracked);
        $this->assertSame(0, $levels[999]->quantity);
        $this->assertFalse($levels[999]->tracked);
    }

    public function test_full_order_cycle_reserve_confirm_and_return(): void
    {
        $this->seedStock([1 => 10, 2 => 5]);
        $gateway = $this->gateway();
        $key = (string) Str::uuid();

        $this->assertTrue($gateway->reserve($key, [1 => 3, 2 => 2])->success);
        $this->assertSame(7, $this->quantity(1));
        $this->assertSame(3, $this->quantity(2));
        $this->assertSame('RESERVED', $this->reservationStatus($key));

        $this->assertTrue($gateway->confirm($key)->success);
        $this->assertSame('CONFIRMED', $this->reservationStatus($key));

        $this->assertTrue($gateway->returnStock($key)->success);
        $this->assertSame('RETURNED', $this->reservationStatus($key));
        $this->assertSame(10, $this->quantity(1));
        $this->assertSame(5, $this->quantity(2));
    }

    public function test_release_gives_back_a_failed_orders_stock(): void
    {
        $this->seedStock([1 => 10]);
        $gateway = $this->gateway();
        $key = (string) Str::uuid();

        $gateway->reserve($key, [1 => 4]);
        $result = $gateway->release($key);

        $this->assertTrue($result->success);
        $this->assertSame('RELEASED', $this->reservationStatus($key));
        $this->assertSame(10, $this->quantity(1));
    }

    public function test_insufficient_stock_returns_details_and_takes_nothing(): void
    {
        $this->seedStock([1 => 10, 2 => 1]);

        $result = $this->gateway()->reserve((string) Str::uuid(), [1 => 2, 2 => 3]);

        $this->assertFalse($result->success);
        $this->assertSame(WarehouseResultCode::InsufficientStock, $result->code);
        $this->assertSame(2, $result->shortage->productId);
        $this->assertSame(3, $result->shortage->requested);
        $this->assertSame(1, $result->shortage->available);
        $this->assertSame(10, $this->quantity(1));
    }

    public function test_an_untracked_product_cannot_be_reserved(): void
    {
        $result = $this->gateway()->reserve((string) Str::uuid(), [999 => 1]);

        $this->assertFalse($result->success);
        $this->assertSame(WarehouseResultCode::ProductNotFound, $result->code);
        $this->assertSame(999, $result->shortage->productId);
    }

    public function test_repeating_a_key_reserves_once_and_new_items_are_refused(): void
    {
        $this->seedStock([1 => 10]);
        $gateway = $this->gateway();
        $key = (string) Str::uuid();

        $this->assertTrue($gateway->reserve($key, [1 => 4])->success);
        $this->assertTrue($gateway->reserve($key, [1 => 4])->success);
        $this->assertSame(6, $this->quantity(1));

        $conflict = $gateway->reserve($key, [1 => 5]);
        $this->assertFalse($conflict->success);
        $this->assertSame(WarehouseResultCode::KeyConflict, $conflict->code);
        $this->assertSame(6, $this->quantity(1));
    }

    public function test_initialize_never_overwrites_and_set_stock_does(): void
    {
        $gateway = $this->gateway();

        $this->assertTrue($gateway->initializeStock(1, 7)->success);

        $again = $gateway->initializeStock(1, 99);
        $this->assertFalse($again->success);
        $this->assertSame(WarehouseResultCode::AlreadyInitialized, $again->code);
        $this->assertSame(7, $again->quantity);
        $this->assertSame(7, $this->quantity(1));

        $this->assertSame(3, $gateway->setStock(1, 3)->quantity);
        $this->assertSame(3, $this->quantity(1));
    }

    public function test_an_invalid_key_is_reported_as_a_request_error(): void
    {
        $this->expectException(WarehouseRequestException::class);

        $this->gateway()->confirm('not-a-uuid');
    }

    /**
     * A real timeout: this connection holds the stock row's lock, so the server
     * stalls on it past the client's deadline. The reservation then completes after
     * the lock is freed, which is the case where a timeout doesn't mean failure.
     */
    public function test_a_real_timeout_does_not_mean_failure_and_retrying_reserves_once(): void
    {
        $this->seedStock([1 => 10]);
        $key = (string) Str::uuid();

        $this->database->beginTransaction();
        $this->database->select('SELECT quantity FROM stock WHERE product_id = 1 FOR UPDATE');

        try {
            $this->gateway(writeTimeoutMs: 300, retries: 0)->reserve($key, [1 => 4]);
            $this->fail('Expected a timeout');
        } catch (WarehouseTimeoutException) {
            // The server is still waiting for the lock.
        } finally {
            $this->database->commit();
        }

        $result = $this->gateway()->reserve($key, [1 => 4]);

        $this->assertTrue($result->success);
        $this->assertSame(6, $this->quantity(1));
    }

    public function test_release_after_a_real_timeout_leaves_stock_whole(): void
    {
        $this->seedStock([1 => 10]);
        $key = (string) Str::uuid();

        $this->database->beginTransaction();
        $this->database->select('SELECT quantity FROM stock WHERE product_id = 1 FOR UPDATE');

        try {
            $this->gateway(writeTimeoutMs: 300, retries: 0)->reserve($key, [1 => 4]);
            $this->fail('Expected a timeout');
        } catch (WarehouseTimeoutException) {
            // The server is still waiting for the lock.
        } finally {
            $this->database->commit();
        }

        $this->assertTrue($this->gateway()->release($key)->success);

        // A later attempt with this key must not take stock either.
        $late = $this->gateway()->reserve($key, [1 => 4]);

        $this->assertFalse($late->success);
        $this->assertSame(WarehouseResultCode::AlreadyReleased, $late->code);
        $this->assertSame('RELEASED', $this->reservationStatus($key));
        $this->assertSame(10, $this->quantity(1));
    }

    public function test_an_expired_read_deadline_fails_promptly_with_a_timeout(): void
    {
        $started = microtime(true);

        try {
            $this->gateway(readTimeoutMs: 1)->getStock([1]);
            $this->fail('Expected a timeout');
        } catch (WarehouseTimeoutException) {
            $this->assertLessThan(1.0, microtime(true) - $started);
        }
    }
}
