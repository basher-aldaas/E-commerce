<?php

namespace Modules\Orders\Exceptions;

use App\Constants\HttpStatusConstants;
use App\Exceptions\ApiException;

class IdempotencyKeyConflictException extends ApiException
{
    protected string $errorType = 'IDEMPOTENCY_KEY_CONFLICT';

    protected int $httpStatusCode = HttpStatusConstants::HTTP_422_UNPROCESSABLE_ENTITY;

    public function getDefaultMessage(): string
    {
        return 'This Idempotency-Key was already used with different order items.';
    }
}
