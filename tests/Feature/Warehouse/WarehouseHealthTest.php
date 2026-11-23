<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Services\Warehouse\DisabledWarehouseGateway;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * GET /api/v1/system/warehouse: an admin-only probe that asks the Warehouse for
 * one product's stock through the existing GetStock. "healthy" needs a real
 * answer; a timeout or a failure after sending is "unknown", never "down".
 */
class WarehouseHealthTest extends ApiTestCase
{
    private function probe(): TestResponse
    {
        $this->actingAsApi($this->makeUser('admin'));

        return $this->getJson('/api/v1/system/warehouse');
    }

    private function assertHealth(TestResponse $response, string $status, ?string $reason): void
    {
        $this->assertEnvelope($response, 200);
        $response->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.reason', $reason)
            ->assertJsonPath('data.probe', 'GetStock');
        $this->assertNotNull($response->json('data.checked_at'));
    }

    private function grpcFailure(int $code): WarehouseUnavailableException
    {
        $error = new WarehouseUnavailableException();
        $error->grpcCode = $code;

        return $error;
    }

    public function test_healthy_when_get_stock_answers_with_the_probe_id(): void
    {
        $response = $this->probe();

        $this->assertHealth($response, 'healthy', null);
        $response->assertJsonPath('data.slow', false);
        $this->assertIsInt($response->json('data.latency_ms'));

        $calls = $this->warehouse->callsTo('getStock');
        $this->assertCount(1, $calls);
        $this->assertSame([1], $calls[0]['args'][0]);
        $this->assertSame([1500], $this->warehouse->deadlines);
    }

    public function test_the_probe_product_id_comes_from_config(): void
    {
        config(['warehouse.health_probe_product_id' => 7]);

        $this->probe();

        $this->assertSame([7], $this->warehouse->callsTo('getStock')[0]['args'][0]);
    }

    public function test_a_slow_answer_is_healthy_but_marked_slow(): void
    {
        config(['warehouse.health_slow_ms' => -1]);

        $response = $this->probe();

        $this->assertHealth($response, 'healthy', null);
        $response->assertJsonPath('data.slow', true);
    }

    public function test_a_reply_without_the_probe_id_is_an_error(): void
    {
        $this->warehouse->replyNext('getStock', []);

        $this->assertHealth($this->probe(), 'error', 'unexpected_reply');
    }

    public function test_a_connect_failure_is_unreachable(): void
    {
        $this->warehouse->failNext('getStock', WarehouseUnavailableException::beforeSending());

        $response = $this->probe();

        $this->assertHealth($response, 'unreachable', 'connect_failed');
        $response->assertJsonPath('data.latency_ms', null);
    }

    public function test_a_timeout_is_unknown_never_down(): void
    {
        $this->warehouse->failNext('getStock', new WarehouseTimeoutException());

        $this->assertHealth($this->probe(), 'unknown', 'deadline_exceeded');
    }

    public function test_a_grpc_internal_error_is_an_error(): void
    {
        $this->warehouse->failNext('getStock', $this->grpcFailure(13));

        $this->assertHealth($this->probe(), 'error', 'grpc_internal');
    }

    public function test_an_invalid_request_is_an_error(): void
    {
        $error = new WarehouseRequestException();
        $error->grpcCode = 3;
        $this->warehouse->failNext('getStock', $error);

        $this->assertHealth($this->probe(), 'error', 'invalid_request');
    }

    public function test_a_failure_after_sending_is_unknown(): void
    {
        $this->warehouse->failNext('getStock', $this->grpcFailure(14));

        $this->assertHealth($this->probe(), 'unknown', 'connection_lost');
    }

    public function test_a_failed_probe_is_not_retried(): void
    {
        $this->warehouse->failNext('getStock', new WarehouseTimeoutException());

        $this->probe();

        $this->assertCount(1, $this->warehouse->callsTo('getStock'));
    }

    public function test_a_disabled_integration_is_unknown(): void
    {
        $this->app->instance(WarehouseGateway::class, new DisabledWarehouseGateway());

        $this->assertHealth($this->probe(), 'unknown', 'integration_disabled');
    }

    public function test_the_response_is_never_cached(): void
    {
        $response = $this->probe();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('ETag'));
    }

    public function test_only_admins_can_probe(): void
    {
        $this->actingAsApi($this->makeUser('customer'));
        $this->getJson('/api/v1/system/warehouse')->assertStatus(403);

        $this->asGuest();
        $this->getJson('/api/v1/system/warehouse')->assertStatus(401);

        $this->assertCount(0, $this->warehouse->callsTo('getStock'));
    }
}
