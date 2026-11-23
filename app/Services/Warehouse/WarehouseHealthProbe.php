<?php

namespace App\Services\Warehouse;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Services\Warehouse\Data\WarehouseHealth;
use Carbon\CarbonImmutable;

/**
 * Asks the Warehouse for one product's stock through the existing GetStock, with
 * its own short budget (connect + call) and no retry. A reply proves the gRPC
 * server and its MySQL both work: GetStock runs a SELECT on the stock table.
 */
class WarehouseHealthProbe
{
    public function __construct(private readonly WarehouseGateway $warehouse) {}

    public function check(): WarehouseHealth
    {
        if ($this->warehouse instanceof DisabledWarehouseGateway) {
            return $this->result(WarehouseHealth::UNKNOWN, 'integration_disabled');
        }

        $productId = (int) config('warehouse.health_probe_product_id');
        $gateway = $this->warehouse->withDeadline((int) config('warehouse.health_budget_ms'));

        $started = hrtime(true);

        try {
            $levels = $gateway->getStock([$productId]);
        } catch (WarehouseException $e) {
            return $this->result(...$this->classify($e));
        }

        $elapsedMs = (hrtime(true) - $started) / 1e6;

        if (! isset($levels[$productId])) {
            return $this->result(WarehouseHealth::ERROR, 'unexpected_reply', $elapsedMs);
        }

        return $this->result(WarehouseHealth::HEALTHY, null, $elapsedMs);
    }

    /** @return array{0: string, 1: string} */
    private function classify(WarehouseException $e): array
    {
        if ($e instanceof WarehouseUnavailableException && ! $e->requestSent) {
            return [WarehouseHealth::UNREACHABLE, 'connect_failed'];
        }

        if ($e instanceof WarehouseTimeoutException) {
            return [WarehouseHealth::UNKNOWN, 'deadline_exceeded'];
        }

        return match ($e->grpcCode) {
            \Grpc\STATUS_INTERNAL, \Grpc\STATUS_UNKNOWN => [WarehouseHealth::ERROR, 'grpc_internal'],
            \Grpc\STATUS_INVALID_ARGUMENT => [WarehouseHealth::ERROR, 'invalid_request'],
            default => [WarehouseHealth::UNKNOWN, 'connection_lost'],
        };
    }

    private function result(string $status, ?string $reason, ?float $elapsedMs = null): WarehouseHealth
    {
        return new WarehouseHealth(
            status: $status,
            reason: $reason,
            latencyMs: $elapsedMs === null ? null : (int) round($elapsedMs),
            slow: $elapsedMs !== null && $elapsedMs > (int) config('warehouse.health_slow_ms'),
            checkedAt: CarbonImmutable::now(),
        );
    }
}
