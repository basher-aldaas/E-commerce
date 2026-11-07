<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Warehouse gRPC Service
    |--------------------------------------------------------------------------
    |
    | The Python service that owns all stock and reservations. Timeouts are
    | short because a customer request is waiting. Reads aren't retried, so a
    | stalled Warehouse can't hold up a product page for long.
    |
    */

    'target' => env('WAREHOUSE_GRPC_TARGET', '127.0.0.1:50051'),

    'read_timeout_ms' => (int) env('WAREHOUSE_GRPC_READ_TIMEOUT_MS', 1000),

    'write_timeout_ms' => (int) env('WAREHOUSE_GRPC_WRITE_TIMEOUT_MS', 3000),

    'write_retries' => (int) env('WAREHOUSE_GRPC_WRITE_RETRIES', 1),

];
