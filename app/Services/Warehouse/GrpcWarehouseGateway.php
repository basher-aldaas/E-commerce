<?php

namespace App\Services\Warehouse;

use App\Exceptions\Warehouse\WarehouseException;
use App\Exceptions\Warehouse\WarehouseRequestException;
use App\Exceptions\Warehouse\WarehouseTimeoutException;
use App\Exceptions\Warehouse\WarehouseUnavailableException;
use App\Grpc\Warehouse\ConfirmRequest;
use App\Grpc\Warehouse\InitializeStockRequest;
use App\Grpc\Warehouse\ReleaseRequest;
use App\Grpc\Warehouse\ReserveRequest;
use App\Grpc\Warehouse\ReturnStockRequest;
use App\Grpc\Warehouse\SetStockRequest;
use App\Grpc\Warehouse\StockItem;
use App\Grpc\Warehouse\StockRequest;
use App\Services\Warehouse\Data\StockLevel;
use App\Services\Warehouse\Data\StockShortage;
use App\Services\Warehouse\Data\WarehouseResult;
use App\Services\Warehouse\Data\WarehouseResultCode;
use Google\Protobuf\Internal\Message;
use Illuminate\Support\Facades\Log;

class GrpcWarehouseGateway implements WarehouseGateway
{
    private const RETRY_DELAY_MICROSECONDS = 100_000;

    public function __construct(
        private readonly WarehouseStub $stub,
        private readonly int $readTimeoutMs,
        private readonly int $writeTimeoutMs,
        private readonly int $writeRetries,
    ) {}

    public function getStock(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $response = $this->call(
            'GetStock',
            (new StockRequest())->setProductIds($productIds),
            $this->readTimeoutMs,
            retries: 0
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

        $response = $this->write(
            'Reserve',
            (new ReserveRequest())->setReservationKey($reservationKey)->setItems($items)
        );

        $shortage = $response->getShortage();

        return new WarehouseResult(
            $response->getSuccess(),
            $this->code($response->getCode()),
            $response->getReason(),
            $shortage === null ? null : new StockShortage(
                $shortage->getProductId(),
                $shortage->getRequested(),
                $shortage->getAvailable(),
            ),
        );
    }

    public function confirm(string $reservationKey): WarehouseResult
    {
        return $this->result($this->write(
            'Confirm',
            (new ConfirmRequest())->setReservationKey($reservationKey)
        ));
    }

    public function release(string $reservationKey): WarehouseResult
    {
        return $this->result($this->write(
            'Release',
            (new ReleaseRequest())->setReservationKey($reservationKey)
        ));
    }

    public function returnStock(string $reservationKey): WarehouseResult
    {
        return $this->result($this->write(
            'ReturnStock',
            (new ReturnStockRequest())->setReservationKey($reservationKey)
        ));
    }

    public function initializeStock(int $productId, int $quantity): WarehouseResult
    {
        return $this->stockResult($this->write(
            'InitializeStock',
            (new InitializeStockRequest())->setProductId($productId)->setQuantity($quantity)
        ));
    }

    public function setStock(int $productId, int $quantity): WarehouseResult
    {
        return $this->stockResult($this->write(
            'SetStock',
            (new SetStockRequest())->setProductId($productId)->setQuantity($quantity)
        ));
    }

    private function write(string $method, Message $request): Message
    {
        return $this->call($method, $request, $this->writeTimeoutMs, $this->writeRetries);
    }

    /**
     * Retrying is safe because every Warehouse write can be repeated without
     * applying twice. Only failures that may be temporary are retried.
     */
    private function call(string $method, Message $request, int $timeoutMs, int $retries): Message
    {
        for ($attempt = 0; ; $attempt++) {
            [$response, $status] = $this->stub
                ->{$method}($request, [], ['timeout' => $timeoutMs * 1000])
                ->wait();

            if ($status->code === \Grpc\STATUS_OK) {
                return $response;
            }

            $mayBeTemporary = in_array(
                $status->code,
                [\Grpc\STATUS_UNAVAILABLE, \Grpc\STATUS_DEADLINE_EXCEEDED],
                true
            );

            if (! $mayBeTemporary || $attempt >= $retries) {
                Log::warning('Warehouse call failed', [
                    'method' => $method,
                    'grpc_code' => $status->code,
                    'grpc_details' => $status->details,
                    'attempts' => $attempt + 1,
                ]);

                throw $this->exceptionFor($status->code);
            }

            usleep(self::RETRY_DELAY_MICROSECONDS);
        }
    }

    private function exceptionFor(int $grpcCode): WarehouseException
    {
        return match ($grpcCode) {
            \Grpc\STATUS_DEADLINE_EXCEEDED => new WarehouseTimeoutException(),
            \Grpc\STATUS_INVALID_ARGUMENT => new WarehouseRequestException(),
            default => new WarehouseUnavailableException(),
        };
    }

    private function result(Message $response): WarehouseResult
    {
        return new WarehouseResult(
            $response->getSuccess(),
            $this->code($response->getCode()),
            $response->getReason(),
        );
    }

    private function stockResult(Message $response): WarehouseResult
    {
        return new WarehouseResult(
            $response->getSuccess(),
            $this->code($response->getCode()),
            $response->getReason(),
            quantity: $response->getQuantity(),
        );
    }

    private function code(int $code): WarehouseResultCode
    {
        return WarehouseResultCode::tryFrom($code) ?? WarehouseResultCode::Unspecified;
    }
}
