<?php

namespace App\Services\Warehouse;

use App\Exceptions\Warehouse\WarehouseException;
use App\Services\Warehouse\Data\StockLevel;
use App\Services\Warehouse\Data\WarehouseResult;

/**
 * The application's only way to reach the Warehouse service, which owns all stock.
 *
 * A business refusal, such as too little stock, comes back as a WarehouseResult.
 * A technical failure throws a WarehouseException. Never call these methods inside
 * a database transaction: a local rollback can't undo work done in the Warehouse.
 *
 * @throws WarehouseException from every method
 */
interface WarehouseGateway
{
    /** Quantities travel as int32 over gRPC, so requests must not exceed this. */
    public const MAX_QUANTITY = 2147483647;

    /**
     * @param  list<int>  $productIds
     * @return array<int, StockLevel> Keyed by product ID.
     */
    public function getStock(array $productIds): array;

    /**
     * Reserves every item or none. Repeating a key never reserves twice.
     *
     * @param  array<int, int>  $quantities  Quantity keyed by product ID.
     */
    public function reserve(string $reservationKey, array $quantities): WarehouseResult;

    public function confirm(string $reservationKey): WarehouseResult;

    /**
     * Cancels an unconfirmed reservation. Safe for a key that never arrived: the
     * key is recorded, so a delayed reserve with that key is refused.
     */
    public function release(string $reservationKey): WarehouseResult;

    /** Puts back the stock of a confirmed reservation. */
    public function returnStock(string $reservationKey): WarehouseResult;

    /** Creates a new product's stock. It never changes existing stock. */
    public function initializeStock(int $productId, int $quantity): WarehouseResult;

    /** Replaces a product's available quantity. This is a deliberate admin action. */
    public function setStock(int $productId, int $quantity): WarehouseResult;
}
