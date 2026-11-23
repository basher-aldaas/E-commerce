<?php

namespace App\Services\Warehouse;

use App\Exceptions\Warehouse\WarehouseException;
use App\Services\Warehouse\Data\StockLevel;
use App\Services\Warehouse\Data\WarehouseResult;

/**
 * The application's only way to reach the Warehouse service, which owns all stock.
 * The contract has exactly three operations; stock levels are managed with the
 * Warehouse's own CLI, never from Laravel.
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
     * A copy whose calls all share one time budget, so one HTTP request (Reserve
     * plus any compensating Release) can never spend longer than this in the
     * Warehouse. A call that would start after the budget is spent throws a
     * WarehouseTimeoutException without being sent.
     */
    public function withDeadline(int $budgetMs): static;

    /**
     * @param  list<int>  $productIds
     * @return array<int, StockLevel> Keyed by product ID.
     */
    public function getStock(array $productIds): array;

    /**
     * Reserves every item or none. Repeating a key never reserves twice. A refusal
     * lists every short item in WarehouseResult::$shortages.
     *
     * @param  array<int, int>  $quantities  Quantity keyed by product ID.
     */
    public function reserve(string $reservationKey, array $quantities): WarehouseResult;

    /**
     * Gives a reservation's stock back: compensation for an order that could not
     * be saved, or a cancelled order. Repeating it never returns stock twice, and
     * an unknown key is recorded so that a delayed reserve with it is refused.
     */
    public function release(string $reservationKey): WarehouseResult;
}
