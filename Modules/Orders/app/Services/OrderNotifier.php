<?php

namespace Modules\Orders\Services;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Modules\Orders\Events\OrderPlaced;
use Modules\Orders\Events\OrderStatusUpdated;
use Modules\Orders\Mail\OrderStatusUpdatedMail;
use Modules\Orders\Models\Order;
use Throwable;

/**
 * Emails and WebSocket messages go out after the order and its stock are
 * settled (the transaction has committed), so a mail or Pusher failure is
 * reported for follow-up but must never look like a failed order. The
 * database stays the source of truth: a browser that missed a message still
 * sees the right status on its next load.
 *
 * The order confirmation email is not sent from here: it travels as an
 * `order.created` event through the transactional outbox and RabbitMQ to the
 * email worker (Modules\Notifications, Requirement 4).
 */
class OrderNotifier
{
    public function placed(Order $order): void
    {
        $this->broadcast(OrderPlaced::from($order));
    }

    public function statusChanged(Order $order): void
    {
        $this->broadcast(OrderStatusUpdated::from($order));

        $order->loadMissing('user');

        if ($order->user !== null) {
            $this->send($order->user->email, new OrderStatusUpdatedMail($order));
        }
    }

    private function broadcast(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            report($e);
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
