<?php

namespace Modules\Orders\Exceptions;

use App\Constants\HttpStatusConstants;
use App\Exceptions\ApiException;

class OrderInProgressException extends ApiException
{
    protected string $errorType = 'ORDER_IN_PROGRESS';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_409_CONFLICT;

    public function getDefaultMessage(): string
    {
        return 'An order with this Idempotency-Key is still being processed. Please try again shortly.';
    }
}
