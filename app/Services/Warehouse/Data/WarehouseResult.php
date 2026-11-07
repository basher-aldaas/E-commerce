<?php

namespace App\Services\Warehouse\Data;

final readonly class WarehouseResult
{
    /**
     * @param  string  $reason  Diagnostic text for logs. Never show it to users.
     * @param  int|null  $quantity  The stored quantity, on stock setup operations.
     */
    public function __construct(
        public bool $success,
        public WarehouseResultCode $code,
        public string $reason = '',
        public ?StockShortage $shortage = null,
        public ?int $quantity = null,
    ) {}
}
