<?php

namespace App\Services\Warehouse;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Grpc\Warehouse\ReleaseRequest;
use App\Grpc\Warehouse\ReserveRequest;
use App\Grpc\Warehouse\StockItem;
use App\Grpc\Warehouse\StockRequest;
use App\Grpc\Warehouse\WarehouseClient;
use App\Services\Warehouse\Data\StockLevel;
use App\Services\Warehouse\Data\StockShortage;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode;
use Google\Protobuf\Internal\Message;
use Illuminate\Support\Facades\Log;

/**
 * Every call has two phases with their own failure:
 *
 * 1. Connect: wait at most connectTimeoutMs for a ready channel. Not ready means
 *    the Warehouse is down or unreachable and nothing was sent:
 *    WarehouseUnavailableException (HTTP 503). This is what keeps a stopped
 *    Warehouse from costing the full deadline.
 * 2. Call: the gRPC deadline. Running out means the request was sent but no
 *    reply came, so it may still have succeeded: WarehouseTimeoutException (504).
 *
 * withDeadline() gives a copy whose calls all share one time budget, so a whole
 * HTTP request (Reserve plus a compensating Release) can never take longer.
 * Nothing is retried automatically.
 */
class GrpcWarehouseGateway implements WarehouseGateway
{
    /** Absolute end of the shared budget (microtime), or null for per-call limits only. */
    private ?float $deadlineAt = null;

    public function __construct(
        private readonly WarehouseClient $client,//الـ client المولّد من الـ proto، مسؤول عن ارسال طلبات ال grpc
        private readonly int $readTimeoutMs,//اقصى مدة لانتظار عملية القراءة
        private readonly int $writeTimeoutMs,//اقصى مدة لانتظار عملية الحجز
        private readonly int $releaseTimeoutMs,//اقصى مدة لانتظار عملية التحرير
        private readonly int $connectTimeoutMs,//اقصى مدة لانتظار جاهزية الاتصال قبل ارسال الطلب
    ) {}

    public function withDeadline(int $budgetMs): static
    {
        $copy = clone $this;
        $copy->deadlineAt = microtime(true) + $budgetMs / 1000;

        return $copy;
    }

    public function getStock(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $response = $this->call(
            'GetStock',
            (new StockRequest())->setProductIds($productIds),
            $this->readTimeoutMs,
        );

        $levels = [];
        foreach ($response->getItems() as $item) {
            $levels[$item->getProductId()] = new StockLevel(
                $item->getProductId(),
                $item->getQuantity(),
                $item->getTracked(),
            );
        }

        return $levels;
    }

    public function reserve(string $reservationKey, array $quantities): WarehouseResult
    {
        $items = [];
        foreach ($quantities as $productId => $quantity) {
            $items[] = (new StockItem())->setProductId($productId)->setQuantity($quantity);
        }

        $response = $this->call(
            'Reserve',
            (new ReserveRequest())->setReservationKey($reservationKey)->setItems($items),
            $this->writeTimeoutMs,
        );

        $shortages = [];
        foreach ($response->getShortages() as $shortage) {
            $shortages[] = new StockShortage(
                $shortage->getProductId(),
                $shortage->getRequested(),
                $shortage->getAvailable(),
                $shortage->getTracked(),
            );
        }

        return new WarehouseResult(
            $response->getSuccess(),
            $this->code($response->getCode()),
            $response->getReason(),
            $shortages,
        );
    }

    public function release(string $reservationKey): WarehouseResult
    {
        $response = $this->call(
            'Release',
            (new ReleaseRequest())->setReservationKey($reservationKey),
            $this->releaseTimeoutMs,
        );

        return new WarehouseResult(
            $response->getSuccess(),
            $this->code($response->getCode()),
            $response->getReason(),
        );
    }

    private function call(string $method, Message $request, int $timeoutMs): Message
    {
        $started = microtime(true);
        $remaining = $this->remainingMs();

        if ($remaining !== null && $remaining <= 0) {
            $this->logFailure($method, 'request budget exhausted before the call', $started);

            throw new WarehouseTimeoutException();
        }

        // Phase 1: a ready connection, within the connect limit (and the budget).
        $connectMs = $remaining === null ? $this->connectTimeoutMs : min($this->connectTimeoutMs, $remaining);

        if (! $this->client->waitForReady($connectMs * 1000)) {
            // Cut short by the budget rather than by the connect limit: we ran out of
            // time, we didn't learn that the Warehouse is down.
            $budgetCut = $connectMs < $this->connectTimeoutMs;
            $this->logFailure($method, $budgetCut ? 'request budget exhausted while connecting' : 'no connection', $started);

            throw $budgetCut ? new WarehouseTimeoutException() : WarehouseUnavailableException::beforeSending();
        }

        // Phase 2: the call itself, with a deadline that also fits in the budget.
        $remaining = $this->remainingMs();
        $deadlineMs = $remaining === null ? $timeoutMs : max(1, min($timeoutMs, $remaining));

        [$response, $status] = $this->client
            ->{$method}($request, [], ['timeout' => $deadlineMs * 1000])
            ->wait();

        if ($status->code === \Grpc\STATUS_OK) {
            return $response;
        }

        $this->logFailure($method, 'call failed', $started, $status->code, $status->details);

        throw $this->exceptionFor($status->code);
    }

    private function remainingMs(): ?int
    {
        return $this->deadlineAt === null
            ? null
            : (int) floor(($this->deadlineAt - microtime(true)) * 1000);
    }

    private function logFailure(string $method, string $phase, float $started, ?int $grpcCode = null, string $details = ''): void
    {
        Log::warning('Warehouse call failed', [
            'method' => $method,
            'phase' => $phase,
            'grpc_code' => $grpcCode,
            'grpc_details' => $details,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private function exceptionFor(int $grpcCode): WarehouseException
    {
        $exception = match ($grpcCode) {
            \Grpc\STATUS_DEADLINE_EXCEEDED => new WarehouseTimeoutException(),
            \Grpc\STATUS_INVALID_ARGUMENT => new WarehouseRequestException(),
            default => new WarehouseUnavailableException(),
        };
        $exception->grpcCode = $grpcCode;

        return $exception;
    }

    private function code(int $code): WarehouseResultCode
    {
        return WarehouseResultCode::tryFrom($code) ?? WarehouseResultCode::Unspecified;
    }
}
