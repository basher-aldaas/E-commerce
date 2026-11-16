<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Decides, per request, whether reads may go to the MySQL read replica.
 *
 * Laravel sends SELECTs to the "read" host once config/database.php has one
 * (DB_READ_HOST). A replica can lag behind the primary, so:
 * - every connection reads the primary from the moment it is created
 *   (AppServiceProvider): auth, permissions, orders, console commands, the
 *   scheduler. Nothing reaches the replica unless a route opts in;
 * - only routes marked `reads.replica` (catalog reads) may use the replica, and
 *   that middleware runs after authentication and the permission check...
 * - ...and not for a user who changed rows within database.read_your_writes_seconds;
 * - onPrimary() forces the primary for one piece of work (catalog-cache refills).
 *
 * Without DB_READ_HOST both PDOs point at the same server, so the routing itself
 * changes nothing (the write marker is still stored in the cache).
 */
class ReadRouting
{
    private bool $replicaAllowed = false;

    public function usePrimary(): void
    {
        $this->replicaAllowed = false;
        DB::connection()->useWriteConnectionWhenReading(true);
    }

    public function allowReplica(): void
    {
        $this->replicaAllowed = true;
        DB::connection()->useWriteConnectionWhenReading(false);
    }

    public function replicaAllowed(): bool
    {
        return $this->replicaAllowed;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function onPrimary(Closure $work): mixed
    {
        $previous = $this->replicaAllowed;
        $this->usePrimary();

        try {
            return $work();
        } finally {
            if ($previous) {
                $this->allowReplica();
            }
        }
    }

    public function markWrite(int $userId): void
    {
        Cache::put($this->key($userId), true, now()->addSeconds($this->window()));
    }

    public function hasRecentWrite(int $userId): bool
    {
        return Cache::has($this->key($userId));
    }

    private function window(): int
    {
        return max(1, (int) config('database.read_your_writes_seconds', 5));
    }

    private function key(int $userId): string
    {
        return "db:recent-write:{$userId}";
    }
}
