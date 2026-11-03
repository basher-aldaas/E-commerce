<?php

namespace Modules\Orders\Exceptions;

use App\Constants\HttpStatusConstants;
use App\Exceptions\ApiException;

/**
 * Thrown when a user tries to view / track / modify an order
 * that does not belong to them (and they are not an admin).
 */
class UnauthorizedOrderAccessException extends ApiException
{
    protected string $errorType = 'ORDER_ACCESS_DENIED';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_403_FORBIDDEN;

    public function getDefaultMessage(): string
    {
        return 'You are not allowed to access this order.';
    }
}