<?php

namespace App\Exceptions\Warehouse;

use App\Constants\HttpStatusConstants;

/**
 * The Warehouse rejected the request as invalid, which means a bug in this
 * application rather than bad user input, since requests are validated first.
 */
class WarehouseRequestException extends WarehouseException
{
    protected string $errorType = 'WAREHOUSE_INVALID_REQUEST';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_500_INTERNAL_SERVER_ERROR;

    public function getDefaultMessage(): string
    {
        return 'The inventory request could not be processed.';
    }
}
