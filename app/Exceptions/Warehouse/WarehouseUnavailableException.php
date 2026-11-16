<?php

namespace App\Exceptions\Warehouse;

use App\Constants\HttpStatusConstants;

class WarehouseUnavailableException extends WarehouseException
{
    protected string $errorType = 'WAREHOUSE_UNAVAILABLE';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_503_SERVICE_UNAVAILABLE;

    /**
     * False when no connection could be made, so the request was never sent and
     * cannot have changed anything in the Warehouse. True (the safe default) when
     * the request may have been sent before the failure.
     */
    public bool $requestSent = true;

    /** No connection: the request never left Laravel. */
    public static function beforeSending(): static
    {
        $exception = new static();
        $exception->requestSent = false;

        return $exception;
    }

    public function getDefaultMessage(): string
    {
        return 'The inventory service is currently unavailable. Please try again later.';
    }
}
