<?php

namespace App\Services\Warehouse\Data;

use Carbon\CarbonImmutable;

/**
 * The result of one health probe.
 *
 * - healthy: GetStock answered from the Warehouse database with the probe id.
 * - unreachable: Laravel couldn't open a connection, so nothing was sent.
 * - error: the Warehouse answered, but with an error (e.g. its database failed).
 * - unknown: no conclusion (timeout, connection lost after sending, disabled).
 *
 * A failed probe is never reported as "the Warehouse is down".
 */
final readonly class WarehouseHealth
{
    public const HEALTHY = 'healthy';

    public const UNREACHABLE = 'unreachable';

    public const ERROR = 'error';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $status,
        public ?string $reason,
        public ?int $latencyMs,
        public bool $slow,
        public CarbonImmutable $checkedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'latency_ms' => $this->latencyMs,
            'slow' => $this->slow,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'probe' => 'GetStock',
        ];
    }
}
