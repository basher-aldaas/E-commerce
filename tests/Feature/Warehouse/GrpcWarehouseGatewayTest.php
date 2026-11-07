<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Handler;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Grpc\Warehouse\ConfirmResponse;
use App\Grpc\Warehouse\InitializeStockResponse;
use App\Grpc\Warehouse\ReserveResponse;
use App\Grpc\Warehouse\ResultCode;
use App\Grpc\Warehouse\SetStockResponse;
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

    private function gateway(ScriptedWarehouseStub $stub, int $retries = 1): GrpcWarehouseGateway
    {
        return new GrpcWarehouseGateway($stub, 1000, 3000, $retries);
    }

    private function okConfirm(): ConfirmResponse
    {
        return (new ConfirmResponse())->setSuccess(true)->setCode(ResultCode::RESULT_OK);
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
        $this->assertNull($result->shortage);

        $this->assertCount(1, $stub->calls);
        $request = $stub->calls[0]['request'];
        $this->assertSame(self::KEY, $request->getReservationKey());
        $sent = [];
        foreach ($request->getItems() as $item) {
            $sent[$item->getProductId()] = $item->getQuantity();
        }
        $this->assertSame([1 => 2, 5 => 3], $sent);
        $this->assertSame(3_000_000, $stub->calls[0]['options']['timeout']);
    }

    public function test_a_refusal_is_a_result_with_shortage_details_not_an_exception(): void
    {
        $stub = new ScriptedWarehouseStub([[
            (new ReserveResponse())
                ->setSuccess(false)
                ->setCode(ResultCode::RESULT_INSUFFICIENT_STOCK)
                ->setReason('insufficient stock')
                ->setShortage((new GrpcStockShortage())->setProductId(5)->setRequested(3)->setAvailable(1)),
            \Grpc\STATUS_OK,
        ]]);

        $result = $this->gateway($stub)->reserve(self::KEY, [5 => 3]);

        $this->assertFalse($result->success);
        $this->assertSame(WarehouseResultCode::InsufficientStock, $result->code);
        $this->assertSame(5, $result->shortage->productId);
        $this->assertSame(3, $result->shortage->requested);
        $this->assertSame(1, $result->shortage->available);
    }

    public function test_writes_retry_once_with_the_same_request_after_a_timeout(): void
    {
        $stub = new ScriptedWarehouseStub([
            [null, \Grpc\STATUS_DEADLINE_EXCEEDED],
            [$this->okConfirm(), \Grpc\STATUS_OK],
        ]);

        $result = $this->gateway($stub)->confirm(self::KEY);

        $this->assertTrue($result->success);
        $this->assertCount(2, $stub->calls);
        $this->assertSame(self::KEY, $stub->calls[1]['request']->getReservationKey());
    }

    public function test_writes_retry_after_unavailable(): void
    {
        $stub = new ScriptedWarehouseStub([
            [null, \Grpc\STATUS_UNAVAILABLE],
            [$this->okConfirm(), \Grpc\STATUS_OK],
        ]);

        $this->assertTrue($this->gateway($stub)->confirm(self::KEY)->success);
    }

    public function test_exhausted_retries_report_a_timeout(): void
    {
        $stub = new ScriptedWarehouseStub([
            [null, \Grpc\STATUS_DEADLINE_EXCEEDED],
            [null, \Grpc\STATUS_DEADLINE_EXCEEDED],
        ]);

        $this->expectException(WarehouseTimeoutException::class);

        $this->gateway($stub)->release(self::KEY);
    }

    public function test_exhausted_retries_report_unavailable(): void
    {
        $stub = new ScriptedWarehouseStub([
            [null, \Grpc\STATUS_UNAVAILABLE],
            [null, \Grpc\STATUS_UNAVAILABLE],
        ]);

        $this->expectException(WarehouseUnavailableException::class);

        $this->gateway($stub)->returnStock(self::KEY);
    }

    public function test_invalid_requests_are_not_retried(): void
    {
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_INVALID_ARGUMENT]]);

        try {
            $this->gateway($stub)->confirm('not-a-uuid');
            $this->fail('Expected an exception');
        } catch (WarehouseRequestException) {
            $this->assertCount(1, $stub->calls);
        }
    }

    public function test_internal_errors_are_not_retried_and_report_unavailable(): void
    {
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_INTERNAL]]);

        try {
            $this->gateway($stub)->confirm(self::KEY);
            $this->fail('Expected an exception');
        } catch (WarehouseUnavailableException) {
            $this->assertCount(1, $stub->calls);
        }
    }

    public function test_stock_operations_report_the_stored_quantity(): void
    {
        $stub = new ScriptedWarehouseStub([
            [
                (new InitializeStockResponse())->setSuccess(false)->setCode(ResultCode::RESULT_ALREADY_INITIALIZED)->setQuantity(8),
                \Grpc\STATUS_OK,
            ],
            [
                (new SetStockResponse())->setSuccess(true)->setCode(ResultCode::RESULT_OK)->setQuantity(50),
                \Grpc\STATUS_OK,
            ],
        ]);

        $gateway = $this->gateway($stub);

        $initialized = $gateway->initializeStock(1, 99);
        $this->assertFalse($initialized->success);
        $this->assertSame(WarehouseResultCode::AlreadyInitialized, $initialized->code);
        $this->assertSame(8, $initialized->quantity);

        $this->assertSame(50, $gateway->setStock(1, 50)->quantity);
    }

    public function test_grpc_details_are_logged_but_never_sent_to_the_user(): void
    {
        Log::spy();
        $secret = 'mysql password rejected at 10.0.0.5';
        $stub = new ScriptedWarehouseStub([[null, \Grpc\STATUS_INTERNAL, $secret]]);
        $gateway = $this->gateway($stub);

        Route::get('/api/warehouse-failure-probe', fn () => $gateway->confirm(self::KEY));

        $response = $this->getJson('/api/warehouse-failure-probe');

        $response->assertStatus(503)
            ->assertJsonPath('status', 0)
            ->assertJsonPath('status_code', 503)
            ->assertJsonPath('message', 'The inventory service is currently unavailable. Please try again later.');
        $this->assertStringNotContainsString('10.0.0.5', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => $context['grpc_details'] === $secret && $context['method'] === 'Confirm'
        );
    }

    public function test_timeouts_render_as_504_in_the_standard_envelope(): void
    {
        $stub = new ScriptedWarehouseStub([
            [null, \Grpc\STATUS_DEADLINE_EXCEEDED],
            [null, \Grpc\STATUS_DEADLINE_EXCEEDED],
        ]);
        $gateway = $this->gateway($stub);

        Route::get('/api/warehouse-timeout-probe', fn () => $gateway->confirm(self::KEY));

        $this->getJson('/api/warehouse-timeout-probe')
            ->assertStatus(504)
            ->assertJsonPath('status', 0)
            ->assertJsonPath('status_code', 504);
    }

    public function test_the_gateway_is_bound_from_config_without_connecting(): void
    {
        config(['warehouse.target' => '127.0.0.1:1']);

        $this->assertInstanceOf(GrpcWarehouseGateway::class, $this->app->make(WarehouseGateway::class));
    }

    public function test_a_closed_port_reports_unavailable_over_a_real_connection(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $gateway = new GrpcWarehouseGateway(
            new \App\Services\Warehouse\WarehouseStub("127.0.0.1:{$port}", [
                'credentials' => \Grpc\ChannelCredentials::createInsecure(),
            ]),
            5000,
            5000,
            0,
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
