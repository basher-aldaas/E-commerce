<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Warehouse gRPC Service
    |--------------------------------------------------------------------------
    |
    | The Python service that owns all stock and reservations. A customer request
    | is waiting, so every limit here is short and nothing waits forever:
    |
    | - connect_timeout_ms: time to get a ready connection. If the Warehouse can't
    |   be reached in that time it is a connection failure (HTTP 503) and nothing
    |   was sent.
    | - read/write/release_timeout_ms: the gRPC deadline of one call, once
    |   connected. Running out means the request was sent but no reply came: a
    |   timeout (HTTP 504), whose outcome is unknown.
    | - request_budget_ms: the most one HTTP request may spend in the Warehouse in
    |   total, Reserve and the compensating Release included. Each call's deadline
    |   is cut to what is left of it.
    |
    | Calls are not retried automatically. Both writes are idempotent by
    | reservation key, so a retry is safe, but it belongs to the client (same
    | Idempotency-Key) or to `warehouse:reconcile`, not to stacked timeouts.
    |
    */

    // While false, no connection is made: stock is unavailable and orders return 503.
    'enabled' => (bool) env('WAREHOUSE_GRPC_ENABLED', false),

    'target' => env('WAREHOUSE_GRPC_TARGET', '127.0.0.1:50051'),

    'connect_timeout_ms' => (int) env('WAREHOUSE_GRPC_CONNECT_TIMEOUT_MS', 500),

    'read_timeout_ms' => (int) env('WAREHOUSE_GRPC_READ_TIMEOUT_MS', 1000),

    'write_timeout_ms' => (int) env('WAREHOUSE_GRPC_WRITE_TIMEOUT_MS', 2500),

    'release_timeout_ms' => (int) env('WAREHOUSE_GRPC_RELEASE_TIMEOUT_MS', 1500),

    // Covers connect + Reserve + connect + Release with the defaults above (5 s).
    'request_budget_ms' => (int) env('WAREHOUSE_GRPC_REQUEST_BUDGET_MS', 5000),

    // Admin health probe (GET /api/v1/system/warehouse): one GetStock for this
    // product id within the budget, no retry. It must be a real id (an empty list
    // never reaches the Warehouse's database); the product need not be stocked.
    'health_probe_product_id' => (int) env('WAREHOUSE_HEALTH_PROBE_PRODUCT_ID', 1),
    'health_budget_ms' => (int) env('WAREHOUSE_HEALTH_BUDGET_MS', 1500),
    // An answer slower than this is still healthy, but flagged as slow.
    'health_slow_ms' => (int) env('WAREHOUSE_HEALTH_SLOW_MS', 750),

    // gRPC channel options. The PHP extension keeps channels open between requests,
    // so these make it notice a restarted (or newly stopped) Warehouse within about
    // a second instead of the library defaults (DNS re-resolution every 30 s,
    // reconnect backoff up to 120 s).
    'channel' => [
        'grpc.dns_min_time_between_resolutions_ms' => 1000,
        'grpc.initial_reconnect_backoff_ms' => 300,
        'grpc.min_reconnect_backoff_ms' => 300,
        'grpc.max_reconnect_backoff_ms' => 1000,
    ],

];
