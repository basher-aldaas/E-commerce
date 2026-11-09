<?php

namespace Modules\Orders\Services;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Modules\Orders\Mail\OrderConfirmationMail;
use Modules\Orders\Mail\OrderStatusUpdatedMail;
use Modules\Orders\Models\Order;
use Throwable;

/**
 * Emails go out after the order and its stock are settled, so a mail failure is
 * reported for follow-up but must never look like a failed order.
 */
class OrderNotifier
{
    public function orderPlaced(Order $order, string $email): void
    {
        $this->send($email, new OrderConfirmationMail($order));
    }

    public function statusChanged(Order $order): void
    {
        $order->loadMissing('user');

        if ($order->user !== null) {
            $this->send($order->user->email, new OrderStatusUpdatedMail($order));
        }
    }

    private function send(string $email, Mailable $mail): void
    {
        try {
            Mail::to($email)->send($mail);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
