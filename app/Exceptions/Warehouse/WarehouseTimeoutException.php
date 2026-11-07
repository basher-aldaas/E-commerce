<?php

namespace App\Exceptions\Warehouse;

use App\Constants\HttpStatusConstants;

/**
 * No reply arrived in time. The operation may still have succeeded, so callers
 * must not treat this as a failure of the operation itself.
 */
class WarehouseTimeoutException extends WarehouseException
{
    protected string $errorType = 'WAREHOUSE_TIMEOUT';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_504_GATEWAY_TIMEOUT;

    public function getDefaultMessage(): string
    {
        return 'The inventory service did not respond in time. Please try again later.';
    }
}
