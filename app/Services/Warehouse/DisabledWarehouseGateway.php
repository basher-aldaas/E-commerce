<?php

namespace App\Services\Warehouse;

use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Services\Warehouse\Data\WarehouseResult;

/**
 * Used until WAREHOUSE_GRPC_ENABLED is turned on. It never opens a connection, so
 * stock reads as unavailable and every stock change fails safely.
 */
class DisabledWarehouseGateway implements WarehouseGateway
{
    public function getStock(array $productIds): array
    {
        throw new WarehouseUnavailableException();
    }

    public function reserve(string $reservationKey, array $quantities): WarehouseResult
    {
        throw new WarehouseUnavailableException();
    }

    public function confirm(string $reservationKey): WarehouseResult
    {
        throw new WarehouseUnavailableException();
    }

    public function release(string $reservationKey): WarehouseResult
    {
        throw new WarehouseUnavailableException();
    }

    public function returnStock(string $reservationKey): WarehouseResult
    {
        throw new WarehouseUnavailableException();
    }

    public function initializeStock(int $productId, int $quantity): WarehouseResult
    {
        throw new WarehouseUnavailableException();
    }

    public function setStock(int $productId, int $quantity): WarehouseResult
    {
        throw new WarehouseUnavailableException();
    }
}
