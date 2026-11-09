<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laravel's own record of a Warehouse reservation. It's written before each
 * Warehouse call, so an interrupted order can always be finished or undone.
 */
class OrderReservation extends Model
{
    /** Reserve was sent, or is about to be. The outcome isn't known yet. */
    public const PENDING = 'pending';

    /** The Warehouse refused, so it holds no stock for this key. */
    public const REJECTED = 'rejected';

    /** No order will exist, but the Warehouse hasn't acknowledged the release. */
    public const RELEASE_PENDING = 'release_pending';

    public const RELEASED = 'released';

    /** The order is saved, but the Warehouse hasn't acknowledged the confirmation. */
    public const SAVED = 'saved';

    public const CONFIRMED = 'confirmed';

    /** The order is being cancelled, but its stock isn't confirmed as returned. */
    public const RETURN_PENDING = 'return_pending';

    public const RETURNED = 'returned';

    /** The Warehouse refused a step that should succeed. A person must look at it. */
    public const ATTENTION = 'attention';

    protected $fillable = [
        'reservation_key',
        'user_id',
        'order_id',
        'idempotency_key',
        'request_hash',
        'items',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
