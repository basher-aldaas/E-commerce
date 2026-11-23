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
 * An in-memory Warehouse following the same rules as the Python service
 * (GetStock, Reserve, Release). tests/Integration checks those rules against the
 * real service.
 */
class FakeWarehouseGateway implements WarehouseGateway
{
    /** @var array<int, int> Quantity keyed by product ID. A missing ID is "not stocked". */
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

    /** @var array<string, list<Closure>> */
    private array $afterHooks = [];

    /** @var array<string, list<mixed>> */
    private array $replies = [];

    /**
     * Runs $hook with the call's arguments right after the next successful call to
     * this method, before the caller sees the result (simulates something happening
     * between two steps of a workflow).
     */
    public function afterNext(string $method, Closure $hook): static
    {
        $this->afterHooks[$method][] = $hook;

        return $this;
    }

    /** Makes the next call to this method return $reply instead of its normal result. */
    public function replyNext(string $method, mixed $reply): static
    {
        $this->replies[$method][] = $reply;

        return $this;
    }

    /** @var list<int> Budgets requested through withDeadline(). */
    public array $deadlines = [];

    /** Same instance, so tests see every call; the budget is only recorded. */
    public function withDeadline(int $budgetMs): static
    {
        $this->deadlines[] = $budgetMs;

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
                    $existing['items'] != $quantities => new WarehouseResult(false, Code::KeyConflict, 'conflict'),
                    default => new WarehouseResult(true, Code::Ok, 'already reserved'),
                };
            }

            ksort($quantities);
            $shortages = [];
            foreach ($quantities as $id => $quantity) {
                $available = $this->stock[$id] ?? 0;
                if (! isset($this->stock[$id]) || $available < $quantity) {
                    $shortages[] = new StockShortage($id, $quantity, $available, isset($this->stock[$id]));
                }
            }

            if ($shortages !== []) {
                return new WarehouseResult(false, Code::InsufficientStock, 'internal: short', $shortages);
            }

            foreach ($quantities as $id => $quantity) {
                $this->stock[$id] -= $quantity;
            }
            $this->reservations[$reservationKey] = ['status' => 'RESERVED', 'items' => $quantities];

            return new WarehouseResult(true, Code::Ok);
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
                return new WarehouseResult(true, Code::AlreadyReleased, 'already released');
            }

            foreach ($reservation['items'] as $id => $quantity) {
                $this->stock[$id] += $quantity;
            }
            $this->reservations[$reservationKey]['status'] = 'RELEASED';

            return new WarehouseResult(true, Code::Ok);
        });
    }

    private function handle(string $method, array $args, Closure $work): mixed
    {
        $this->calls[] = ['method' => $method, 'args' => $args, 'transaction_level' => DB::transactionLevel()];

        $failure = isset($this->failures[$method]) ? array_shift($this->failures[$method]) : null;

        if ($failure !== null && ! $failure['after_applying']) {
            throw $failure['error'];
        }

        $result = empty($this->replies[$method]) ? $work() : array_shift($this->replies[$method]);

        if ($failure !== null) {
            throw $failure['error'];
        }

        if (($hook = isset($this->afterHooks[$method]) ? array_shift($this->afterHooks[$method]) : null) !== null) {
            $hook(...$args);
        }

        return $result;
    }
}
