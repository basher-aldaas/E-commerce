<?php

namespace App\Exceptions\Warehouse;

use App\Constants\HttpStatusConstants;

class WarehouseUnavailableException extends WarehouseException
{
    protected string $errorType = 'WAREHOUSE_UNAVAILABLE';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_503_SERVICE_UNAVAILABLE;

    public function getDefaultMessage(): string
    {
        return 'The inventory service is currently unavailable. Please try again later.';
    }
}
