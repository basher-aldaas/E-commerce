<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Facades\Mail;
use Modules\Notifications\Mail\OrderConfirmationMail;
use Modules\Notifications\Messaging\OrderCreatedMessage;

class OrderConfirmationSender
{
    public function send(OrderCreatedMessage $event): array
    {
        $sent = Mail::to($event->customerEmail, $event->customerName !== '' ? $event->customerName : null)
            ->send(new OrderConfirmationMail($event));

        //بجيب رد smtp
        $reply = null;
        if ($sent !== null && preg_match_all("/< (250 [^\r\n]*)/", $sent->getDebug(), $m)) {
            $reply = trim(end($m[1]));
        }

        return ['email_message_id' => OrderConfirmationMail::messageIdFor($event), 'smtp_reply' => $reply];
    }
}
