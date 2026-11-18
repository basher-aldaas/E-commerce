<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Facades\Mail;
use Modules\Notifications\Mail\OrderConfirmationMail;
use Modules\Notifications\Messaging\OrderCreatedMessage;

/**
 * Sends the order confirmation email through the configured mailer (SMTP in
 * the worker container, from the MAIL_* variables in .env). Throws when the
 * mail server refuses or cannot be reached, so the caller can decide not to ACK.
 */
class OrderConfirmationSender
{
    /**
     * @return array{email_message_id: string, smtp_reply: string|null} what the
     *                                                                   mail server accepted
     */
    public function send(OrderCreatedMessage $event): array
    {
        $sent = Mail::to($event->customerEmail, $event->customerName !== '' ? $event->customerName : null)
            ->send(new OrderConfirmationMail($event));

        // The server's last reply to DATA, e.g. "250 2.0.0 Ok: queued as ...".
        $reply = null;
        if ($sent !== null && preg_match_all("/< (250 [^\r\n]*)/", $sent->getDebug(), $m)) {
            $reply = trim(end($m[1]));
        }

        return ['email_message_id' => OrderConfirmationMail::messageIdFor($event), 'smtp_reply' => $reply];
    }
}
