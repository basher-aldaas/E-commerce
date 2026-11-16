<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Handler;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Grpc\Warehouse\ReleaseResponse;
use App\Grpc\Warehouse\ReserveResponse;
use App\Grpc\Warehouse\ResultCode;
use App\Grpc\Warehouse\WarehouseClient;
use App\Grpc\Warehouse\StockLevel as GrpcStockLevel;
use App\Grpc\Warehouse\StockResponse;
use App\Grpc\Warehouse\StockShortage as GrpcStockShortage;
use App\Services\Warehouse\Data\WarehouseResultCode;
use App\Services\Warehouse\GrpcWarehouseGateway;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GrpcWarehouseGatewayTest extends TestCase
{
    private const KEY = '7f3c1a52-6f0e-4f2b-9a51-0d7f6a2b1c34';

    /** read 1000 ms, write 2500 ms, release 1500 ms, connect 500 ms (the defaults). */
    private function gateway(ScriptedWarehouseStub $stub): GrpcWarehouseGateway
    {
        return new GrpcWarehouseGateway($stub, 1000, 2500, 1500, 500);
    }

    private function okRelease(): ReleaseResponse
    {
        return (new ReleaseResponse())->setSuccess(true)->setCode(ResultCode::RESULT_OK);
    }

    public function test_get_stock_maps_levels_by_product_id(): void
    {
        $stub = new ScriptedWarehouseStub([[
            (new StockResponse())->setItems([
                (new GrpcStockLevel())->setProductId(1)->setQuantity(8)->setTracked(true),
                (new GrpcStockLevel())->setProductId(4)->setQuantity(0)->setTracked(false),
            ]),
            \Grpc\STATUS_OK,
        ]]);

        $levels = $this->gateway($stub)->getStock([1, 4]);

        $this->assertSame(8, $levels[1]->quantity);
        $this->assertTrue($levels[1]->tracked);
        $this->assertSame(0, $levels[4]->quantity);
        $this->assertFalse($levels[4]->tracked);
    }

    public function test_get_stock_without_ids_makes_no_call(): void
    {
        $stub = new ScriptedWarehouseStub([]);

        $this->assertSame([], $this->gateway($stub)->getStock([]));
        $this->assertSame([], $stub->calls);
    }

    public function test_reads_use_the_read_timeout_and_are_not_retried(): void
    {
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_UNAVAILABLE]]);

        try {
            $this->gateway($stub)->getStock([1]);
            $this->fail('Expected an exception');
        } catch (WarehouseUnavailableException) {
            $this->assertCount(1, $stub->calls);
            $this->assertSame(1_000_000, $stub->calls[0]['options']['timeout']);
        }
    }

    public function test_reserve_sends_one_request_with_every_item(): void
    {
        $stub = new ScriptedWarehouseStub([[
            (new ReserveResponse())->setSuccess(true)->setCode(ResultCode::RESULT_OK),
            \Grpc\STATUS_OK,
        ]]);

        $result = $this->gateway($stub)->reserve(self::KEY, [1 => 2, 5 => 3]);

        $this->assertTrue($result->success);
        $this->assertSame(WarehouseResultCode::Ok, $result->code);
        $this->assertSame([], $result->shortages);

        $this->assertCount(1, $stub->calls);
        $request = $stub->calls[0]['request'];
        $this->assertSame(self::KEY, $request->getReservationKey());
        $sent = [];
        foreach ($request->getItems() as $item) {
            $sent[$item->getProductId()] = $item->getQuantity();
        }
        $this->assertSame([1 => 2, 5 => 3], $sent);
        $this->assertSame(2_500_000, $stub->calls[0]['options']['timeout']);
    }

    public function test_a_refusal_is_a_result_listing_every_shortage_not_an_exception(): void
    {
        $stub = new ScriptedWarehouseStub([[
            (new ReserveResponse())
                ->setSuccess(false)
                ->setCode(ResultCode::RESULT_INSUFFICIENT_STOCK)
                ->setReason('insufficient stock')
                ->setShortages([
                    (new GrpcStockShortage())->setProductId(5)->setRequested(3)->setAvailable(1)->setTracked(true),
                    (new GrpcStockShortage())->setProductId(7)->setRequested(2)->setAvailable(0)->setTracked(false),
                ]),
            \Grpc\STATUS_OK,
        ]]);

        $result = $this->gateway($stub)->reserve(self::KEY, [5 => 3, 7 => 2]);

        $this->assertFalse($result->success);
        $this->assertSame(WarehouseResultCode::InsufficientStock, $result->code);
        $this->assertCount(2, $result->shortages);
        $this->assertSame([5, 3, 1, true], [$result->shortages[0]->productId, $result->shortages[0]->requested, $result->shortages[0]->available, $result->shortages[0]->tracked]);
        $this->assertSame([7, 2, 0, false], [$result->shortages[1]->productId, $result->shortages[1]->requested, $result->shortages[1]->available, $result->shortages[1]->tracked]);
    }

    public function test_a_repeated_release_is_a_success_with_its_own_code(): void
    {
        $stub = new ScriptedWarehouseStub([[
            (new ReleaseResponse())->setSuccess(true)->setCode(ResultCode::RESULT_ALREADY_RELEASED),
            \Grpc\STATUS_OK,
        ]]);

        $result = $this->gateway($stub)->release(self::KEY);

        $this->assertTrue($result->success);
        $this->assertSame(WarehouseResultCode::AlreadyReleased, $result->code);
        $this->assertSame('Release', $stub->calls[0]['method']);
    }

    public function test_a_write_timeout_is_reported_at_once_and_never_retried(): void
    {
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_DEADLINE_EXCEEDED]]);

        try {
            $this->gateway($stub)->reserve(self::KEY, [1 => 1]);
            $this->fail('Expected a timeout');
        } catch (WarehouseTimeoutException) {
            $this->assertCount(1, $stub->calls);
        }
    }

    public function test_unavailable_after_connecting_is_reported_at_once_and_never_retried(): void
    {
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_UNAVAILABLE]]);

        try {
            $this->gateway($stub)->release(self::KEY);
            $this->fail('Expected unavailable');
        } catch (WarehouseUnavailableException $e) {
            $this->assertCount(1, $stub->calls);
            $this->assertTrue($e->requestSent, 'Failed after sending: the outcome is unknown');
        }
    }

    public function test_release_uses_its_own_deadline(): void
    {
        $stub = new ScriptedWarehouseStub([[$this->okRelease(), \Grpc\STATUS_OK]]);

        $this->gateway($stub)->release(self::KEY);

        $this->assertSame(1_500_000, $stub->calls[0]['options']['timeout']);
    }

    public function test_no_connection_is_a_fast_connection_failure_and_nothing_is_sent(): void
    {
        $stub = new ScriptedWarehouseStub([]);
        $stub->ready = false;

        foreach (['getStock' => [[1]], 'reserve' => [self::KEY, [1 => 1]], 'release' => [self::KEY]] as $method => $args) {
            try {
                $this->gateway($stub)->{$method}(...$args);
                $this->fail("Expected {$method} to report unavailable");
            } catch (WarehouseUnavailableException $e) {
                // A connection failure (503), not a timeout (504), and known unsent.
                $this->assertFalse($e->requestSent);
            }
        }

        $this->assertSame([], $stub->calls, 'Nothing may be sent without a connection');
        $this->assertSame([500_000, 500_000, 500_000], $stub->readyWaits);
    }

    public function test_a_budget_caps_every_call_deadline(): void
    {
        $stub = new ScriptedWarehouseStub([[$this->okRelease(), \Grpc\STATUS_OK]]);

        $this->gateway($stub)->withDeadline(800)->release(self::KEY);

        $this->assertLessThanOrEqual(800_000, $stub->readyWaits[0], 'connect wait fits in the budget');
        $this->assertLessThanOrEqual(800_000, $stub->calls[0]['options']['timeout']);
        $this->assertGreaterThan(0, $stub->calls[0]['options']['timeout']);
    }

    public function test_a_spent_budget_is_a_timeout_and_nothing_is_sent(): void
    {
        $stub = new ScriptedWarehouseStub([]);
        $budgeted = $this->gateway($stub)->withDeadline(1);
        usleep(5_000);

        $this->expectException(WarehouseTimeoutException::class);

        try {
            $budgeted->release(self::KEY);
        } finally {
            $this->assertSame([], $stub->calls);
            $this->assertSame([], $stub->readyWaits);
        }
    }

    public function test_a_connect_wait_cut_short_by_the_budget_is_a_timeout_not_unavailable(): void
    {
        $stub = new ScriptedWarehouseStub([]);
        $stub->ready = false;

        $this->expectException(WarehouseTimeoutException::class);

        $this->gateway($stub)->withDeadline(100)->release(self::KEY);
    }

    public function test_with_deadline_returns_a_copy_and_leaves_the_shared_gateway_unbudgeted(): void
    {
        $stub = new ScriptedWarehouseStub([[$this->okRelease(), \Grpc\STATUS_OK]]);
        $gateway = $this->gateway($stub);

        $this->assertNotSame($gateway, $gateway->withDeadline(1));
        $gateway->release(self::KEY);

        $this->assertSame(1_500_000, $stub->calls[0]['options']['timeout']);
    }

    public function test_invalid_requests_are_not_retried(): void
    {
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_INVALID_ARGUMENT]]);

        try {
            $this->gateway($stub)->release('not-a-uuid');
            $this->fail('Expected an exception');
        } catch (WarehouseRequestException) {
            $this->assertCount(1, $stub->calls);
        }
    }

    public function test_internal_errors_are_not_retried_and_report_unavailable(): void
    {
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_INTERNAL]]);

        try {
            $this->gateway($stub)->release(self::KEY);
            $this->fail('Expected an exception');
        } catch (WarehouseUnavailableException) {
            $this->assertCount(1, $stub->calls);
        }
    }

    public function test_the_gateway_exposes_exactly_the_three_contract_operations(): void
    {
        $methods = array_map(fn ($m) => $m->getName(), (new \ReflectionClass(WarehouseGateway::class))->getMethods());
        sort($methods);
        // withDeadline() only limits time on the client; it is not a remote operation.
        $this->assertSame(['getStock', 'release', 'reserve', 'withDeadline'], $methods);

        $rpcs = array_values(array_diff(
            array_map(fn ($m) => $m->getName(), (new \ReflectionClass(WarehouseClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC)),
            array_map(fn ($m) => $m->getName(), (new \ReflectionClass(\Grpc\BaseStub::class))->getMethods(\ReflectionMethod::IS_PUBLIC)),
        ));
        sort($rpcs);
        $this->assertSame(['GetStock', 'Release', 'Reserve'], $rpcs);
    }

    public function test_grpc_details_are_logged_but_never_sent_to_the_user(): void
    {
        Log::spy();
        $secret = 'mysql password rejected at 10.0.0.5';
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_INTERNAL, $secret]]);
        $gateway = $this->gateway($stub);

        Route::get('/api/warehouse-failure-probe', fn () => $gateway->release(self::KEY));

        $response = $this->getJson('/api/warehouse-failure-probe');

        $response->assertStatus(503)
            ->assertJsonPath('status', 0)
            ->assertJsonPath('status_code', 503)
            ->assertJsonPath('message', 'The inventory service is currently unavailable. Please try again later.');
        $this->assertStringNotContainsString('10.0.0.5', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => $context['grpc_details'] === $secret && $context['method'] === 'Release'
        );
    }

    public function test_timeouts_render_as_504_in_the_standard_envelope(): void
    {
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_DEADLINE_EXCEEDED]]);
        $gateway = $this->gateway($stub);

        Route::get('/api/warehouse-timeout-probe', fn () => $gateway->release(self::KEY));

        $this->getJson('/api/warehouse-timeout-probe')
            ->assertStatus(504)
            ->assertJsonPath('status', 0)
            ->assertJsonPath('status_code', 504);
    }

    public function test_the_gateway_is_bound_from_config_without_connecting(): void
    {
        config(['warehouse.enabled' => true, 'warehouse.target' => '127.0.0.1:1']);

        $this->assertInstanceOf(GrpcWarehouseGateway::class, $this->app->make(WarehouseGateway::class));
    }

    public function test_a_closed_port_reports_unavailable_over_a_real_connection(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $gateway = new GrpcWarehouseGateway(
            new WarehouseClient("127.0.0.1:{$port}", [
                'credentials' => \Grpc\ChannelCredentials::createInsecure(),
            ]),
            5000,
            5000,
            5000,
            5000,
        );

        $started = microtime(true);

        try {
            $gateway->getStock([1]);
            $this->fail('Expected an exception');
        } catch (WarehouseUnavailableException) {
            $this->assertLessThan(5.5, microtime(true) - $started);
        }
    }
}
