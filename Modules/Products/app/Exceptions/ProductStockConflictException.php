<?php

namespace Modules\Products\Exceptions;

use App\Constants\HttpStatusConstants;
use App\Exceptions\ApiException;

/**
 * The Warehouse already holds different stock under the new product's ID. The
 * product is not created, because existing Warehouse stock is never overwritten.
 */
class ProductStockConflictException extends ApiException
{
    protected string $errorType = 'PRODUCT_STOCK_CONFLICT';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_409_CONFLICT;

    public function getDefaultMessage(): string
    {
        return 'The inventory service already has stock for this product ID.';
    }
}
