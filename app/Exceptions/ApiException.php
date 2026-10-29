<?php

namespace App\Exceptions;

use App\Http\Responses\Response;
use Exception;
use Throwable;
use Illuminate\Http\JsonResponse;
use App\Constants\HttpStatusConstants;


/**
 * Foundation for all API exceptions in the application
 *
 * Ensures consistent error responses with:
 * - Standard status indicator ('success'|'failure')
 * - Human-readable message
 * - Structured error details
 * - Proper HTTP status codes
 */
abstract class ApiException extends Exception
{
    /**
     * @var string Machine-readable error code
     */
    protected string $errorType;

    /**
     * HTTP status code
     */
    protected int $httpStatusCode = HttpStatusConstants::HTTP_400_BAD_REQUEST;

    /**
     * Detailed error messages
     */
    protected array $errors = [];

    public function __construct(
        string $message = '',
        array $errors = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: $this->getDefaultMessage(),
            $code,
            $previous
        );

        $this->errors = $errors;
    }

    /**
     * Generate JSON response
     */
    public function render(): JsonResponse
    {
        return Response::Error(
            data: $this->getErrors(),
            message: $this->getMessage(),
            code: $this->getHttpStatusCode()
        );
    }

    /**
     * Get the default human-readable error message.
     *
     * This message will be used if none is provided to the constructor.
     *
     * @return string
     */
    abstract public function getDefaultMessage(): string;

    /**
     * Get the machine-readable error type.
     *
     * This should return a string constant that uniquely identifies
     * the type of error that occurred.
     *
     * @return string
     */
    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /**
     * Get the HTTP status code for the response.
     *
     * @return int
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get additional error details for context.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
