<?php

namespace Modules\Notifications\Messaging;

/**
 * An `order.created` event as the email worker receives it. The worker owns no
 * database: everything it needs travels in the message. This class reads the
 * JSON contract only; it does not depend on the Orders module's classes.
 */
final class OrderCreatedMessage
{
    /**
     * @param  list<array{product_id: int, name: string|null, quantity: int, unit_price: string}>  $items
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $occurredAt,
        public readonly int $orderId,
        public readonly string $status,
        public readonly string $totalAmount,
        public readonly array $items,
        public readonly string $customerName,
        public readonly string $customerEmail,
    ) {}

    public static function fromJson(string $body): self
    {
        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidMessageException('Body is not valid JSON: '.$e->getMessage());
        }

        if (! is_array($event) || ($event['event_type'] ?? null) !== 'order.created') {
            throw new InvalidMessageException('Not an order.created event.');
        }

        $order = $event['data']['order'] ?? null;
        $customer = $event['data']['customer'] ?? null;

        if (! is_array($order) || ! is_array($customer)) {
            throw new InvalidMessageException('order.created without order or customer data.');
        }

        $email = $customer['email'] ?? null;
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidMessageException('order.created without a valid customer email.');
        }

        if (! is_int($order['id'] ?? null) || ! is_array($order['items'] ?? null) || ! is_string($event['event_id'] ?? null)) {
            throw new InvalidMessageException('order.created is missing event_id, order id or items.');
        }

        return new self(
            eventId: $event['event_id'],
            occurredAt: (string) ($event['occurred_at'] ?? ''),
            orderId: $order['id'],
            status: (string) ($order['status'] ?? 'pending'),
            totalAmount: (string) ($order['total_amount'] ?? '0.00'),
            items: array_values(array_map(fn ($item) => [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => isset($item['name']) ? (string) $item['name'] : null,
                'quantity' => (int) ($item['quantity'] ?? 0),
                'unit_price' => (string) ($item['unit_price'] ?? '0.00'),
            ], $order['items'])),
            customerName: (string) ($customer['name'] ?? ''),
            customerEmail: $email,
        );
    }
}
