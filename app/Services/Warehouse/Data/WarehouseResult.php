<?php

namespace App\Services\Warehouse\Data;

final readonly class WarehouseResult
{
    /**
     * @param  string  $reason  Diagnostic text for logs. Never show it to users.
     * @param  list<StockShortage>  $shortages  Every short item, on an insufficient-stock refusal.
     */
    public function __construct(
        public bool $success,
        public WarehouseResultCode $code,
        public string $reason = '',
        public array $shortages = [],
    ) {}
}
