<?php

namespace App\Services\Warehouse\Data;

use App\Grpc\Warehouse\ResultCode;

enum WarehouseResultCode: int
{
    case Unspecified = ResultCode::RESULT_UNSPECIFIED;
    case Ok = ResultCode::RESULT_OK;
    case InsufficientStock = ResultCode::RESULT_INSUFFICIENT_STOCK;
    case ProductNotFound = ResultCode::RESULT_PRODUCT_NOT_FOUND;
    case ReservationNotFound = ResultCode::RESULT_RESERVATION_NOT_FOUND;
    case KeyConflict = ResultCode::RESULT_KEY_CONFLICT;
    case AlreadyReleased = ResultCode::RESULT_ALREADY_RELEASED;
    case InvalidState = ResultCode::RESULT_INVALID_STATE;
    case AlreadyInitialized = ResultCode::RESULT_ALREADY_INITIALIZED;
}
