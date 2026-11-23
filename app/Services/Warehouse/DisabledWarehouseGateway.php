<?php

namespace App\Services\Warehouse;

use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Services\Warehouse\Data\WarehouseResult;

/**
 * Used while WAREHOUSE_GRPC_ENABLED is off. It never opens a connection, so
 * stock reads as unavailable and every reservation fails safely.
 */
class DisabledWarehouseGateway implements WarehouseGateway
{
    public function withDeadline(int $budgetMs): static
    {
        return $this;
    }

    public function getStock(array $productIds): array
    {
        throw new WarehouseUnavailableException();
    }

    public function reserve(string $reservationKey, array $quantities): WarehouseResult
    {
        throw new WarehouseUnavailableException();
    }

    public function release(string $reservationKey): WarehouseResult
    {
        throw new WarehouseUnavailableException();
    }
}
