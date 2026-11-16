<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laravel's own record of a Warehouse reservation. It's written before each
 * Warehouse call, so an interrupted order can always be finished or undone, and it
 * holds the only copy of the key that Release needs. It stores no quantities: the
 * Warehouse owns those.
 *
 *   pending ──reserve ok + order saved──> placed ──cancel──> cancel_pending ──> cancelled
 *      │                                                          (release ok)
 *      ├──refused──> rejected
 *      └──timeout / save failed──> release_pending ──release ok──> released
 */
class OrderReservation extends Model
{
    /** Reserve was sent, or is about to be. The outcome isn't known yet. */
    public const PENDING = 'pending';

    /** The Warehouse refused, so it holds no stock for this key. */
    public const REJECTED = 'rejected';

    /** The order is saved and its stock is held in the Warehouse. */
    public const PLACED = 'placed';

    /** No order will exist, but the Warehouse hasn't acknowledged the release. */
    public const RELEASE_PENDING = 'release_pending';

    /** No order exists and the Warehouse gave the stock back (compensation). */
    public const RELEASED = 'released';

    /** The order is being cancelled, but its stock isn't confirmed as released. */
    public const CANCEL_PENDING = 'cancel_pending';

    /** The order is cancelled and the Warehouse gave its stock back. */
    public const CANCELLED = 'cancelled';

    /** The Warehouse refused a step that should succeed. A person must look at it. */
    public const ATTENTION = 'attention';

    /** States in which the Warehouse may still hold stock for this key. */
    public const HOLDS_STOCK = [self::PENDING, self::PLACED, self::RELEASE_PENDING, self::CANCEL_PENDING];

    /** Every state, in workflow order. */
    public const STATES = [
        self::PENDING, self::REJECTED, self::PLACED, self::RELEASE_PENDING,
        self::RELEASED, self::CANCEL_PENDING, self::CANCELLED, self::ATTENTION,
    ];

    /**
     * What the Warehouse is expected to hold for a reservation in this state. It is
     * derived from Laravel's own record, not read from the Warehouse (the contract
     * has no per-key lookup), and is used for admin diagnostics only.
     *
     * held: Reserve confirmed and the order saved. not_held: refused, never sent,
     * or Release confirmed. unsettled: a Release is still waiting for its reply.
     * unknown: Reserve may be in flight (pending), or a person must check (attention).
     */
    public static function warehouseExpectation(string $status): string
    {
        return match ($status) {
            self::PLACED => 'held',
            self::REJECTED, self::RELEASED, self::CANCELLED => 'not_held',
            self::RELEASE_PENDING, self::CANCEL_PENDING => 'unsettled',
            default => 'unknown',
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Modules\Users\Models\User::class);
    }

    protected $fillable = [
        'reservation_key',
        'user_id',
        'order_id',
        'idempotency_key',
        'request_hash',
        'status',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
