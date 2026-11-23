<?php

namespace Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Modules\Notifications\Messaging\OrderCreatedMessage;

/**
 * Sent to the customer when a new order is created.
 *
 * Built from the `order.created` event by the email worker and sent
 * synchronously there (deliberately not ShouldQueue): the worker ACKs the
 * RabbitMQ message only after this send returned without an error.
 */
class OrderConfirmationMail extends Mailable
{
    public function __construct(
        public readonly OrderCreatedMessage $event
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order #'.$this->event->orderId.' confirmation',
        );
    }

    /** Message-ID derived from the event id: a duplicate delivery is recognisable. */
    public static function messageIdFor(OrderCreatedMessage $event): string
    {
        return $event->eventId.'@order-events.dukkan';
    }

    public function headers(): Headers
    {
        return new Headers(messageId: self::messageIdFor($this->event), text: [
            'X-Order-Id' => (string) $this->event->orderId,
            'X-Event-Id' => $this->event->eventId,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'notifications::emails.order-confirmation',
        );
    }
}
