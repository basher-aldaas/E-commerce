<?php

namespace App\Exceptions\Warehouse;

use App\Exceptions\ApiException;

/**
 * The Warehouse call failed or its outcome is unknown.
 *
 * Messages are safe to show to users. gRPC details are only written to the log.
 */
abstract class WarehouseException extends ApiException
{
    /**
     * The gRPC status code when the Warehouse call itself failed (null when it was
     * never made, e.g. no connection). For diagnostics only, never shown to users.
     */
    public ?int $grpcCode = null;
}
