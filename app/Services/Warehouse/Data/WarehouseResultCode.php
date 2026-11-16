<?php

namespace App\Services\Warehouse\Data;

use App\Grpc\Warehouse\ResultCode;

enum WarehouseResultCode: int
{
    case Unspecified = ResultCode::RESULT_UNSPECIFIED;
    case Ok = ResultCode::RESULT_OK;
    case InsufficientStock = ResultCode::RESULT_INSUFFICIENT_STOCK;
    case KeyConflict = ResultCode::RESULT_KEY_CONFLICT;
    case AlreadyReleased = ResultCode::RESULT_ALREADY_RELEASED;
}
