<?php

namespace Tests\Support;

use App\Services\Warehouse\Data\StockLevel;
use App\Services\Warehouse\Data\StockShortage;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode as Code;
use App\Services\Warehouse\WarehouseGateway;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * An in-memory Warehouse following the same rules as the Python service.
 * tests/Integration checks those rules against the real service.
 */
class FakeWarehouseGateway implements WarehouseGateway
{
    /** @var array<int, int> Quantity keyed by product ID. */
    public array $stock = [];

    /** @var array<string, array{status: string, items: array<int, int>}> */
    public array $reservations = [];

    /** @var list<array{method: string, args: array, transaction_level: int}> */
    public array $calls = [];

    /** @var array<string, list<array{error: Throwable, after_applying: bool}>> */
    private array $failures = [];

    /** Makes the next call to this method throw, before it has any effect. */
    public function failNext(string $method, Throwable $error, int $times = 1): static
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[$method][] = ['error' => $error, 'after_applying' => false];
        }

        return $this;
    }

    /** Makes the next call take effect but lose its reply, as a real timeout can. */
    public function loseNextReply(string $method, Throwable $error): static
    {
        $this->failures[$method][] = ['error' => $error, 'after_applying' => true];

        return $this;
    }

    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call['method'] === $method));
    }

    public function getStock(array $productIds): array
    {
        return $this->handle(__FUNCTION__, func_get_args(), function () use ($productIds) {
            $levels = [];
            foreach (array_unique($productIds) as $id) {
                $levels[$id] = new StockLevel($id, $this->stock[$id] ?? 0, isset($this->stock[$id]));
            }

            return $levels;
        });
    }

    public function reserve(string $reservationKey, array $quantities): WarehouseResult
    {
        return $this->handle(__FUNCTION__, func_get_args(), function () use ($reservationKey, $quantities) {
            $existing = $this->reservations[$reservationKey] ?? null;

            if ($existing !== null) {
                return match (true) {
                    $existing['status'] === 'RELEASED' => new WarehouseResult(false, Code::AlreadyReleased, 'released'),
                    $existing['status'] === 'RETURNED' => new WarehouseResult(false, Code::InvalidState, 'returned'),
                    $existing['items'] != $quantities => new WarehouseResult(false, Code::KeyConflict, 'conflict'),
                    default => new WarehouseResult(true, Code::Ok, 'already reserved'),
                };
            }

            foreach ($quantities as $id => $quantity) {
                if (! isset($this->stock[$id])) {
                    return new WarehouseResult(false, Code::ProductNotFound, 'internal: not found', new StockShortage($id, $quantity, 0));
                }
                if ($this->stock[$id] < $quantity) {
                    return new WarehouseResult(false, Code::InsufficientStock, 'internal: short', new StockShortage($id, $quantity, $this->stock[$id]));
                }
            }

            foreach ($quantities as $id => $quantity) {
                $this->stock[$id] -= $quantity;
            }
            $this->reservations[$reservationKey] = ['status' => 'RESERVED', 'items' => $quantities];

            return new WarehouseResult(true, Code::Ok);
        });
    }

    public function confirm(string $reservationKey): WarehouseResult
    {
        return $this->handle(__FUNCTION__, func_get_args(), function () use ($reservationKey) {
            $status = $this->reservations[$reservationKey]['status'] ?? null;

            if ($status === 'RESERVED') {
                $this->reservations[$reservationKey]['status'] = 'CONFIRMED';
            }

            return match ($status) {
                null => new WarehouseResult(false, Code::ReservationNotFound, 'not found'),
                'RELEASED' => new WarehouseResult(false, Code::AlreadyReleased, 'released'),
                'RETURNED' => new WarehouseResult(false, Code::InvalidState, 'returned'),
                default => new WarehouseResult(true, Code::Ok),
            };
        });
    }

    public function release(string $reservationKey): WarehouseResult
    {
        return $this->handle(__FUNCTION__, func_get_args(), function () use ($reservationKey) {
            $reservation = $this->reservations[$reservationKey] ?? null;

            if ($reservation === null) {
                $this->reservations[$reservationKey] = ['status' => 'RELEASED', 'items' => []];

                return new WarehouseResult(true, Code::Ok, 'recorded');
            }

            if ($reservation['status'] === 'RELEASED') {
                return new WarehouseResult(true, Code::Ok, 'already released');
            }

            if ($reservation['status'] !== 'RESERVED') {
                return new WarehouseResult(false, Code::InvalidState, 'cannot release');
            }

            $this->restore($reservation['items']);
            $this->reservations[$reservationKey]['status'] = 'RELEASED';

            return new WarehouseResult(true, Code::Ok);
        });
    }

    public function returnStock(string $reservationKey): WarehouseResult
    {
        return $this->handle(__FUNCTION__, func_get_args(), function () use ($reservationKey) {
            $reservation = $this->reservations[$reservationKey] ?? null;

            if ($reservation === null) {
                return new WarehouseResult(false, Code::ReservationNotFound, 'not found');
            }

            if ($reservation['status'] === 'RETURNED') {
                return new WarehouseResult(true, Code::Ok, 'already returned');
            }

            if ($reservation['status'] !== 'CONFIRMED') {
                return new WarehouseResult(false, Code::InvalidState, 'cannot return');
            }

            $this->restore($reservation['items']);
            $this->reservations[$reservationKey]['status'] = 'RETURNED';

            return new WarehouseResult(true, Code::Ok);
        });
    }

    public function initializeStock(int $productId, int $quantity): WarehouseResult
    {
        return $this->handle(__FUNCTION__, func_get_args(), function () use ($productId, $quantity) {
            if (isset($this->stock[$productId])) {
                return new WarehouseResult(false, Code::AlreadyInitialized, 'exists', quantity: $this->stock[$productId]);
            }

            $this->stock[$productId] = $quantity;

            return new WarehouseResult(true, Code::Ok, quantity: $quantity);
        });
    }

    public function setStock(int $productId, int $quantity): WarehouseResult
    {
        return $this->handle(__FUNCTION__, func_get_args(), function () use ($productId, $quantity) {
            $this->stock[$productId] = $quantity;

            return new WarehouseResult(true, Code::Ok, quantity: $quantity);
        });
    }

    private function restore(array $items): void
    {
        foreach ($items as $id => $quantity) {
            $this->stock[$id] += $quantity;
        }
    }

    private function handle(string $method, array $args, Closure $work): mixed
    {
        $this->calls[] = ['method' => $method, 'args' => $args, 'transaction_level' => DB::transactionLevel()];

        $failure = isset($this->failures[$method]) ? array_shift($this->failures[$method]) : null;

        if ($failure !== null && ! $failure['after_applying']) {
            throw $failure['error'];
        }

        $result = $work();

        if ($failure !== null) {
            throw $failure['error'];
        }

        return $result;
    }
}
