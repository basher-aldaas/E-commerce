<?php

namespace Modules\Orders\Exceptions;

use App\Constants\HttpStatusConstants;
use App\Exceptions\ApiException;

/**
 * Thrown when an order cannot be found for the given id.
 *
 * Using this instead of findOrFail() lets us return an order-specific
 * message ("The requested order was not found.") rather than a generic one.
 */
class OrderNotFoundException extends ApiException
{
    protected string $errorType = 'ORDER_NOT_FOUND';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_404_NOT_FOUND;

    public function getDefaultMessage(): string
    {
        return 'The requested order was not found.';
    }
}