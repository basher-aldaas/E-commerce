<?php

namespace App\Messaging\Outbox;

use Illuminate\Database\Eloquent\Model;

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

    public function body(): string
    {
        return json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
