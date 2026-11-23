<?php

namespace Tests\Integration;

use App\Exceptions\Warehouse\WarehouseException;
use App\Services\Warehouse\GrpcWarehouseGateway;
use App\Grpc\Warehouse\WarehouseClient;
use Grpc\ChannelCredentials;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Starts a real Python gRPC server on a free port, on the Warehouse's own MySQL
 * database (warehouse-mysql, published only on 127.0.0.1:3308 by
 * docker-compose.yml). There is no separate test database: the tests delete every
 * row in that database, so they only run when WAREHOUSE_TESTS_DATABASE names it
 * exactly. Reseed afterwards with `docker compose exec warehouse python -m app.cli seed`.
 * Reports a visible skip when the service or database isn't available.
 *
 * Stop the scheduler first (`docker compose stop scheduler`, then `start` it
 * again): its every-minute warehouse:reconcile works on the same two databases
 * and would release the stalled reservations the concurrency tests set up.
 */
trait UsesWarehouseTestServer
{
    private static ?Process $server = null;

    private static int $port = 0;

    private static ?string $skipReason = null;

    private Connection $database;

    /** @return array{host: string, port: string, username: string, password: string, database: string} */
    private static function warehouseDatabase(): array
    {
        return [
            'host' => (string) env('WAREHOUSE_TEST_DB_HOST', '127.0.0.1'),
            'port' => (string) env('WAREHOUSE_TEST_DB_PORT', '3308'),
            'username' => (string) env('WAREHOUSE_TEST_DB_USER', 'warehouse'),
            'password' => (string) env('WAREHOUSE_TEST_DB_PASSWORD', 'warehouse'),
            'database' => (string) env('WAREHOUSE_TEST_DB_NAME', 'warehouse'),
        ];
    }

    protected function prepareWarehouseTestServer(): void
    {
        $target = self::warehouseDatabase();

        if (env('WAREHOUSE_TESTS_DATABASE') !== $target['database']) {
            $this->markTestSkipped(
                "These tests delete every row in the Warehouse database '{$target['database']}'. "
                ."Set WAREHOUSE_TESTS_DATABASE={$target['database']} to run them."
            );
        }

        config(['database.connections.warehouse_integration' => array_merge(
            config('database.connections.mysql'),
            $target
        )]);

        try {
            $this->database = DB::connection('warehouse_integration');
            $this->database->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped("The Warehouse database {$target['host']}:{$target['port']}/{$target['database']} is not available: ".$e->getMessage());
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

        parent::tearDownAfterClass();
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

        self::$port = $this->freePort();
        $mysql = self::warehouseDatabase();

        self::$server = new Process([$python, '-m', 'app.grpc_server'], $servicePath, [
            'WAREHOUSE_DB_HOST' => $mysql['host'],
            'WAREHOUSE_DB_PORT' => $mysql['port'],
            'WAREHOUSE_DB_USER' => $mysql['username'],
            'WAREHOUSE_DB_PASSWORD' => $mysql['password'],
            'WAREHOUSE_DB_NAME' => $mysql['database'],
            'WAREHOUSE_GRPC_HOST' => '127.0.0.1',
            'WAREHOUSE_GRPC_PORT' => (string) self::$port,
        ]);
        self::$server->setTimeout(null);
        self::$server->start();

        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            try {
                $this->realGateway(readTimeoutMs: 500)->getStock([1]);

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

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    private function realGateway(
        int $readTimeoutMs = 2000,
        int $writeTimeoutMs = 8000,
        int $releaseTimeoutMs = 8000,
        int $connectTimeoutMs = 2000,
        ?int $port = null,
    ): GrpcWarehouseGateway {
        return new GrpcWarehouseGateway(
            new WarehouseClient('127.0.0.1:'.($port ?? self::$port), ['credentials' => ChannelCredentials::createInsecure()] + config('warehouse.channel', [])),
            $readTimeoutMs,
            $writeTimeoutMs,
            $releaseTimeoutMs,
            $connectTimeoutMs,
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

    /** Holds the row lock so the server stalls on it, then always lets go. */
    private function whileStockRowIsLocked(int $productId, callable $work): void
    {
        $this->database->beginTransaction();
        $this->database->select('SELECT quantity FROM stock WHERE product_id = ? FOR UPDATE', [$productId]);

        try {
            $work();
        } finally {
            $this->database->commit();
        }
    }
}
