<?php

namespace Modules\Orders\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Orders\Models\Order;

/**
 * Sent to the customer when an admin changes the status of their order.
 *
 * Queued so the status-update request returns immediately.
 */
class OrderStatusUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order #'.$this->order->id.' is now '.$this->order->status,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'orders::emails.order-status-updated',
        );
    }
}