<?php

namespace Tests\Integration;

use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Services\Warehouse\Data\WarehouseResultCode;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real PHP client, real Python gRPC server, real MySQL.
 */
class WarehouseGrpcIntegrationTest extends TestCase
{
    use UsesWarehouseTestServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareWarehouseTestServer();
    }

    public function test_get_stock_reads_quantities_and_tracked_flags(): void
    {
        $this->seedStock([1 => 8, 2 => 0]);

        $levels = $this->realGateway()->getStock([1, 2, 999]);

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
        $gateway = $this->realGateway();
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
        $gateway = $this->realGateway();
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

        $result = $this->realGateway()->reserve((string) Str::uuid(), [1 => 2, 2 => 3]);

        $this->assertFalse($result->success);
        $this->assertSame(WarehouseResultCode::InsufficientStock, $result->code);
        $this->assertSame(2, $result->shortage->productId);
        $this->assertSame(3, $result->shortage->requested);
        $this->assertSame(1, $result->shortage->available);
        $this->assertSame(10, $this->quantity(1));
    }

    public function test_an_untracked_product_cannot_be_reserved(): void
    {
        $result = $this->realGateway()->reserve((string) Str::uuid(), [999 => 1]);

        $this->assertFalse($result->success);
        $this->assertSame(WarehouseResultCode::ProductNotFound, $result->code);
        $this->assertSame(999, $result->shortage->productId);
    }

    public function test_repeating_a_key_reserves_once_and_new_items_are_refused(): void
    {
        $this->seedStock([1 => 10]);
        $gateway = $this->realGateway();
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
        $gateway = $this->realGateway();

        $this->assertTrue($gateway->initializeStock(1, 7)->success);

        $again = $gateway->initializeStock(1, 99);
        $this->assertFalse($again->success);
        $this->assertSame(WarehouseResultCode::AlreadyInitialized, $again->code);
        $this->assertSame(7, $again->quantity);
        $this->assertSame(7, $this->quantity(1));

        $this->assertSame(3, $gateway->setStock(1, 3)->quantity);
        $this->assertSame(3, $this->quantity(1));
    }

    public function test_the_largest_int32_quantity_works_end_to_end(): void
    {
        $gateway = $this->realGateway();

        $this->assertSame(2147483647, $gateway->setStock(1, 2147483647)->quantity);
        $this->assertSame(2147483647, $gateway->getStock([1])[1]->quantity);
    }

    public function test_an_invalid_key_is_reported_as_a_request_error(): void
    {
        $this->expectException(WarehouseRequestException::class);

        $this->realGateway()->confirm('not-a-uuid');
    }

    /**
     * A real timeout: the server stalls on a held row lock past the client's
     * deadline. Afterwards a retry with the same key must only deduct once.
     */
    public function test_a_real_timeout_does_not_mean_failure_and_retrying_reserves_once(): void
    {
        $this->seedStock([1 => 10]);
        $key = (string) Str::uuid();

        $this->whileStockRowIsLocked(1, function () use ($key) {
            try {
                $this->realGateway(writeTimeoutMs: 300, retries: 0)->reserve($key, [1 => 4]);
                $this->fail('Expected a timeout');
            } catch (WarehouseTimeoutException) {
                // The server is still waiting for the lock.
            }
        });

        $result = $this->realGateway()->reserve($key, [1 => 4]);

        $this->assertTrue($result->success);
        $this->assertSame(6, $this->quantity(1));
    }

    public function test_release_after_a_real_timeout_leaves_stock_whole(): void
    {
        $this->seedStock([1 => 10]);
        $key = (string) Str::uuid();

        $this->whileStockRowIsLocked(1, function () use ($key) {
            try {
                $this->realGateway(writeTimeoutMs: 300, retries: 0)->reserve($key, [1 => 4]);
                $this->fail('Expected a timeout');
            } catch (WarehouseTimeoutException) {
                // The server is still waiting for the lock.
            }
        });

        $this->assertTrue($this->realGateway()->release($key)->success);

        // A later attempt with this key must not take stock either.
        $late = $this->realGateway()->reserve($key, [1 => 4]);

        $this->assertFalse($late->success);
        $this->assertSame(WarehouseResultCode::AlreadyReleased, $late->code);
        $this->assertSame('RELEASED', $this->reservationStatus($key));
        $this->assertSame(10, $this->quantity(1));
    }

    public function test_an_expired_read_deadline_fails_promptly_with_a_timeout(): void
    {
        $started = microtime(true);

        try {
            $this->realGateway(readTimeoutMs: 1)->getStock([1]);
            $this->fail('Expected a timeout');
        } catch (WarehouseTimeoutException) {
            $this->assertLessThan(1.0, microtime(true) - $started);
        }
    }
}
