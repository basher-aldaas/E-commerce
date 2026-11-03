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
 * Sent to the customer when a new order is created.
 *
 * Queued so order creation returns immediately; delivery happens on a worker.
 */
class OrderConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order #'.$this->order->id.' confirmation',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'orders::emails.order-confirmation',
        );
    }
}