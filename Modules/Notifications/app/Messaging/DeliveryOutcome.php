<?php

namespace Modules\Notifications\Messaging;

/** What the worker told RabbitMQ about one delivery. */
enum DeliveryOutcome: string
{
    /** Email sent, then basic.ack: the broker deletes the message. */
    case Acked = 'acked';

    /** Send failed, basic.nack requeue=true: the broker retries (or dead-letters after the limit). */
    case Retried = 'retried';

    /** Unprocessable message, basic.reject requeue=false: dead-lettered at once. */
    case Rejected = 'rejected';
}
