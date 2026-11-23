<?php

namespace Tests\Integration;

use App\Services\Warehouse\WarehouseGateway;
use Tests\Feature\Requirement1\ApiTestCase;

/** The health probe against the real Python service and its MySQL database. */
class WarehouseHealthIntegrationTest extends ApiTestCase
{
    use UsesWarehouseTestServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareWarehouseTestServer();
    }

    public function test_a_real_warehouse_is_healthy(): void
    {
        $this->app->instance(WarehouseGateway::class, $this->realGateway());
        $this->actingAsApi($this->makeUser('admin'));

        $this->getJson('/api/v1/system/warehouse')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.reason', null);
    }

    public function test_a_closed_port_is_unreachable_within_the_budget(): void
    {
        $this->app->instance(
            WarehouseGateway::class,
            $this->realGateway(connectTimeoutMs: 500, port: $this->freePort())
        );
        $this->actingAsApi($this->makeUser('admin'));

        $started = microtime(true);
        $response = $this->getJson('/api/v1/system/warehouse');
        $elapsed = microtime(true) - $started;

        $response->assertOk()
            ->assertJsonPath('data.status', 'unreachable')
            ->assertJsonPath('data.reason', 'connect_failed');
        $this->assertLessThan(1.6, $elapsed);
    }
}
