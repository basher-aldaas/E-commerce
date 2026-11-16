<?php

namespace Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Orders\Models\OrderReservation;

/**
 * One order attempt for admin diagnostics. The reservation key, the request hash
 * and the full Idempotency-Key hash are deliberately left out: only the first 8
 * hex characters of that hash are shown, so a retry can be matched to its attempt.
 *
 * @mixin OrderReservation
 */
class ReservationDiagnosticResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'warehouse_expectation' => OrderReservation::warehouseExpectation($this->status),
            'order_id' => $this->order_id,
            'user' => ['id' => $this->user->id, 'name' => $this->user->name],
            'idempotency_fp' => $this->idempotency_key === null ? null : substr($this->idempotency_key, 0, 8),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
