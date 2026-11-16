<?php

namespace Tests\Integration;

use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Services\Warehouse\Data\WarehouseResultCode;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real generated PHP client, real Python gRPC server, real MySQL.
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

    public function test_full_cycle_reserve_then_release(): void
    {
        $this->seedStock([1 => 10, 2 => 5]);
        $gateway = $this->realGateway();
        $key = (string) Str::uuid();

        $this->assertTrue($gateway->reserve($key, [1 => 3, 2 => 2])->success);
        $this->assertSame(7, $this->quantity(1));
        $this->assertSame(3, $this->quantity(2));
        $this->assertSame('RESERVED', $this->reservationStatus($key));

        $released = $gateway->release($key);
        $this->assertTrue($released->success);
        $this->assertSame(WarehouseResultCode::Ok, $released->code);
        $this->assertSame('RELEASED', $this->reservationStatus($key));
        $this->assertSame(10, $this->quantity(1));
        $this->assertSame(5, $this->quantity(2));
    }

    public function test_releasing_twice_returns_the_stock_once(): void
    {
        $this->seedStock([1 => 10]);
        $gateway = $this->realGateway();
        $key = (string) Str::uuid();

        $gateway->reserve($key, [1 => 4]);
        $gateway->release($key);
        $again = $gateway->release($key);

        $this->assertTrue($again->success);
        $this->assertSame(WarehouseResultCode::AlreadyReleased, $again->code);
        $this->assertSame(10, $this->quantity(1));
    }

    public function test_insufficient_stock_lists_every_shortage_and_takes_nothing(): void
    {
        $this->seedStock([1 => 10, 2 => 1, 3 => 0]);

        $result = $this->realGateway()->reserve((string) Str::uuid(), [1 => 2, 2 => 3, 3 => 1, 999 => 1]);

        $this->assertFalse($result->success);
        $this->assertSame(WarehouseResultCode::InsufficientStock, $result->code);
        $this->assertSame(
            [[2, 3, 1, true], [3, 1, 0, true], [999, 1, 0, false]],
            array_map(fn ($s) => [$s->productId, $s->requested, $s->available, $s->tracked], $result->shortages)
        );
        $this->assertSame([10, 1, 0], [$this->quantity(1), $this->quantity(2), $this->quantity(3)]);
    }

    public function test_the_requested_quantity_is_never_reduced(): void
    {
        $this->seedStock([1 => 10]);

        $result = $this->realGateway()->reserve((string) Str::uuid(), [1 => 20]);

        $this->assertFalse($result->success);
        $this->assertSame(20, $result->shortages[0]->requested);
        $this->assertSame(10, $result->shortages[0]->available);
        $this->assertSame(10, $this->quantity(1));
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

    public function test_the_largest_int32_quantity_works_end_to_end(): void
    {
        $this->seedStock([1 => 2147483647]);
        $key = (string) Str::uuid();

        $this->assertSame(2147483647, $this->realGateway()->getStock([1])[1]->quantity);
        $this->assertTrue($this->realGateway()->reserve($key, [1 => 2147483647])->success);
        $this->assertSame(0, $this->quantity(1));
    }

    public function test_an_invalid_key_is_reported_as_a_request_error(): void
    {
        $this->expectException(WarehouseRequestException::class);

        $this->realGateway()->release('not-a-uuid');
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
                $this->realGateway(writeTimeoutMs: 300)->reserve($key, [1 => 4]);
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
                $this->realGateway(writeTimeoutMs: 300)->reserve($key, [1 => 4]);
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
