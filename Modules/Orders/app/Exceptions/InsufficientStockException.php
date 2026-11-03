<?php

namespace Modules\Orders\Exceptions;

use App\Constants\HttpStatusConstants;
use App\Exceptions\ApiException;

/**
 * Thrown during order creation when a requested quantity exceeds
 * the available stock of a product.
 *
 * 409 Conflict is used because the request itself is well-formed,
 * but it conflicts with the current state of the inventory.
 */
class InsufficientStockException extends ApiException
{
    protected string $errorType = 'INSUFFICIENT_STOCK';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_409_CONFLICT;

    public function getDefaultMessage(): string
    {
        return 'Insufficient stock for the requested product.';
    }
}