<?php

namespace App\Services\Warehouse;

use App\Grpc\Warehouse\ConfirmRequest;
use App\Grpc\Warehouse\ConfirmResponse;
use App\Grpc\Warehouse\InitializeStockRequest;
use App\Grpc\Warehouse\InitializeStockResponse;
use App\Grpc\Warehouse\ReleaseRequest;
use App\Grpc\Warehouse\ReleaseResponse;
use App\Grpc\Warehouse\ReserveRequest;
use App\Grpc\Warehouse\ReserveResponse;
use App\Grpc\Warehouse\ReturnStockRequest;
use App\Grpc\Warehouse\ReturnStockResponse;
use App\Grpc\Warehouse\SetStockRequest;
use App\Grpc\Warehouse\SetStockResponse;
use App\Grpc\Warehouse\StockRequest;
use App\Grpc\Warehouse\StockResponse;
use Grpc\BaseStub;
use Grpc\UnaryCall;

/**
 * gRPC transport for proto/warehouse.proto in the Warehouse service. Written by
 * hand, so update it when RPCs change. Use WarehouseGateway in application code.
 */
class WarehouseStub extends BaseStub
{
    public function GetStock(StockRequest $request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest('/Warehouse/GetStock', $request, [StockResponse::class, 'decode'], $metadata, $options);
    }

    public function Reserve(ReserveRequest $request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest('/Warehouse/Reserve', $request, [ReserveResponse::class, 'decode'], $metadata, $options);
    }

    public function Confirm(ConfirmRequest $request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest('/Warehouse/Confirm', $request, [ConfirmResponse::class, 'decode'], $metadata, $options);
    }

    public function Release(ReleaseRequest $request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest('/Warehouse/Release', $request, [ReleaseResponse::class, 'decode'], $metadata, $options);
    }

    public function ReturnStock(ReturnStockRequest $request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest('/Warehouse/ReturnStock', $request, [ReturnStockResponse::class, 'decode'], $metadata, $options);
    }

    public function InitializeStock(InitializeStockRequest $request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest('/Warehouse/InitializeStock', $request, [InitializeStockResponse::class, 'decode'], $metadata, $options);
    }

    public function SetStock(SetStockRequest $request, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest('/Warehouse/SetStock', $request, [SetStockResponse::class, 'decode'], $metadata, $options);
    }
}
