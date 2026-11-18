<?php

namespace App\Messaging\Outbox;

use Illuminate\Database\Eloquent\Model;

/**
 * One event waiting to be (or already) published to RabbitMQ.
 *
 * @property int $id
 * @property string $message_id
 * @property string $type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array $payload
 * @property int $attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon $available_at
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class OutboxMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'outbox_messages';

    protected $fillable = [
        'message_id',
        'type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'attempts',
        'last_error',
        'available_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** The exact bytes sent to the broker. */
    public function body(): string
    {
        return json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
