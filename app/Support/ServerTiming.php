<?php

namespace App\Support;

/**
 * Collects named durations during one request for the Server-Timing response
 * header (shown in the browser's network panel and readable from Postman), e.g.
 *   Server-Timing: catalog;dur=0.8;desc="HIT", stock;dur=4.1
 * Bound as a scoped (per-request) service.
 */
class ServerTiming
{
    /** @var array<string, array{dur: float, desc: ?string}> */
    private array $entries = [];

    public function reset(): void
    {
        $this->entries = [];
    }

    public function add(string $name, float $milliseconds, ?string $description = null): void
    {
        $this->entries[$name] = ['dur' => round($milliseconds, 2), 'desc' => $description];
    }

    /** Runs $work and records how long it took. */
    public function measure(string $name, callable $work, ?string $description = null): mixed
    {
        $started = hrtime(true);

        try {
            return $work();
        } finally {
            $this->add($name, (hrtime(true) - $started) / 1e6, $description);
        }
    }

    public function header(): ?string
    {
        if ($this->entries === []) {
            return null;
        }

        return implode(', ', array_map(
            fn (string $name, array $entry) => $name.';dur='.$entry['dur']
                .($entry['desc'] === null ? '' : ';desc="'.$entry['desc'].'"'),
            array_keys($this->entries),
            $this->entries,
        ));
    }
}
