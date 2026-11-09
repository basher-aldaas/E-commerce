<?php

namespace Modules\Orders\Exceptions;

use App\Constants\HttpStatusConstants;
use App\Exceptions\ApiException;

/**
 * Thrown when a status change would disagree with the order's stock, such as
 * reopening a cancelled order whose stock has gone back to the Warehouse.
 */
class OrderStatusConflictException extends ApiException
{
    protected string $errorType = 'ORDER_STATUS_CONFLICT';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_409_CONFLICT;

    public function getDefaultMessage(): string
    {
        return 'The order cannot move to this status.';
    }
}
