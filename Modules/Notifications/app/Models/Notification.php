<?php

namespace Modules\Notifications\Models;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Notifications\Database\Factories\NotificationFactory;

class Notification extends Model
{
    use Queueable;

    public function __construct(
        public int $orderId,
        public string $status
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'message' => "Order status changed to {$this->status}",
        ];
    }
    // protected static function newFactory(): NotificationFactory
    // {
    //     // return NotificationFactory::new();
    // }
}
