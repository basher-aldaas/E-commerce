<?php

namespace Modules\Notifications\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Throwable;

/**
 * Sends dead letters back for another try once the cause is fixed (e.g. the
 * SMTP credentials). Never loses one: each message is republished to the main
 * exchange with publisher confirms, and only after the broker confirmed the
 * copy is the dead letter ACKed (removed from the DLQ). A failure leaves it in
 * the DLQ. The copy starts with a fresh delivery count; the original death
 * history stays readable in the x-replayed-* headers.
 */
class DeadLetterReplayer
{
    /**
     * @return list<array{message_id: string|null, replayed: bool, error: string|null}>
     */
    public function replay(AMQPChannel $channel, int $limit): array
    {
        $config = config('messaging.topology');
        $dlq = $config['order_created_email']['dead_letter_queue'];
        $routingKey = $config['order_created_email']['routing_key'];

        $channel->confirm_select();
        $returned = null;
        $nacked = false;
        // A returned (unroutable) message is still confirmed with basic.ack
        // afterwards, so a return must count as a failure.
        $channel->set_return_listener(function ($code, $text) use (&$returned) {
            $returned = "returned by the broker: {$code} {$text}";
        });
        $channel->set_nack_handler(function () use (&$nacked) {
            $nacked = true;
        });
        $report = [];

        while (count($report) < $limit) {
            $dead = $channel->basic_get($dlq);
            if ($dead === null) {
                break;
            }

            $id = $dead->has('message_id') ? $dead->get('message_id') : null;

            try {
                $returned = null;
                $nacked = false;
                $channel->basic_publish($this->copy($dead), $config['exchange'], $routingKey, true);
                $channel->wait_for_pending_acks_returns((float) config('messaging.outbox.confirm_timeout_seconds'));

                if ($returned !== null || $nacked) {
                    throw new RuntimeException($returned ?? 'rejected by the broker (basic.nack)');
                }

                $dead->ack();
                $report[] = ['message_id' => $id, 'replayed' => true, 'error' => null];
            } catch (Throwable $e) {
                // Not confirmed: keep the dead letter where it is.
                try {
                    $dead->nack(true);
                } catch (Throwable) {
                    // Channel gone: the broker requeues unacked messages itself.
                }
                $report[] = ['message_id' => $id, 'replayed' => false, 'error' => $e->getMessage()];

                break;
            }
        }

        return $report;
    }

    private function copy(AMQPMessage $dead): AMQPMessage
    {
        $properties = $dead->get_properties();
        $headers = isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable
            ? $properties['application_headers']->getNativeData()
            : [];

        $deaths = $headers['x-death'] ?? [];
        $first = is_array($deaths) && isset($deaths[0]) && is_array($deaths[0]) ? $deaths[0] : [];

        // Broker-owned headers must not be copied onto a new message.
        foreach (array_keys($headers) as $name) {
            if (str_starts_with($name, 'x-death') || str_starts_with($name, 'x-first-death') || str_starts_with($name, 'x-last-death') || $name === 'x-delivery-count') {
                unset($headers[$name]);
            }
        }

        $headers['x-replay-count'] = (int) ($headers['x-replay-count'] ?? 0) + 1;
        $headers['x-replayed-death-reason'] = (string) ($first['reason'] ?? 'unknown');
        $headers['x-replayed-death-queue'] = (string) ($first['queue'] ?? 'unknown');

        $properties['application_headers'] = new AMQPTable($headers);
        $properties['delivery_mode'] = AMQPMessage::DELIVERY_MODE_PERSISTENT;

        if (! isset($properties['message_id'])) {
            throw new RuntimeException('A dead letter without message_id cannot be traced; leave it for manual review.');
        }

        return new AMQPMessage($dead->getBody(), $properties);
    }
}
