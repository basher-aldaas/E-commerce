<?php

namespace Tests\Feature\Requirement4;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\MockInterface;
use Modules\Notifications\Mail\OrderConfirmationMail;
use Modules\Notifications\Messaging\DeliveryOutcome;
use Modules\Notifications\Messaging\OrderCreatedEmailConsumer;
use Modules\Notifications\Messaging\OrderCreatedMessage;
use Modules\Notifications\Services\OrderConfirmationSender;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Requirement 4: the email worker ACKs a message only after the email was
 * sent, never ACKs a failure (NACK + requeue, the broker counts and finally
 * dead-letters it) and rejects unprocessable messages straight to the DLQ.
 * No RabbitMQ needed: the channel is a mock that records what was sent back.
 */
class OrderCreatedEmailConsumerTest extends TestCase
{
    private AMQPChannel&MockInterface $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'messaging.topology.order_created_email.max_retries' => 3,
            'messaging.topology.order_created_email.retry_backoff_ms' => 0,
            'messaging.topology.order_created_email.min_interval_ms' => 0,
        ]);
        Mail::fake();
        $this->channel = Mockery::mock(AMQPChannel::class);
    }

    private function event(array $overrides = []): array
    {
        return array_replace_recursive([
            'event_id' => '6f1c2a8e-0000-4000-8000-000000000001',
            'event_type' => 'order.created',
            'event_version' => 1,
            'occurred_at' => '2026-11-17T10:00:00+00:00',
            'data' => [
                'order' => [
                    'id' => 42,
                    'status' => 'pending',
                    'total_amount' => '28.00',
                    'created_at' => '2026-11-17T10:00:00+00:00',
                    'items' => [
                        ['product_id' => 1, 'name' => 'Desk Lamp', 'quantity' => 2, 'unit_price' => '12.50'],
                        ['product_id' => 2, 'name' => 'Notebook', 'quantity' => 1, 'unit_price' => '3.00'],
                    ],
                ],
                'customer' => ['id' => 7, 'name' => 'Lina Customer', 'email' => 'lina@example.test'],
            ],
        ], $overrides);
    }

    private function delivery(string $body, ?int $deliveryCount = null): AMQPMessage
    {
        $properties = ['message_id' => '6f1c2a8e-0000-4000-8000-000000000001', 'delivery_mode' => 2];
        if ($deliveryCount !== null) {
            $properties['application_headers'] = new AMQPTable(['x-delivery-count' => $deliveryCount]);
        }

        $message = new AMQPMessage($body, $properties);
        $message->setChannel($this->channel);
        $message->setDeliveryTag(99);

        return $message;
    }

    private function consumer(): OrderCreatedEmailConsumer
    {
        return app(OrderCreatedEmailConsumer::class);
    }

    private function failingMailer(): void
    {
        $this->app->instance(OrderConfirmationSender::class, new class extends OrderConfirmationSender
        {
            public function send(OrderCreatedMessage $event): array
            {
                throw new TransportException('Connection to "sandbox.smtp.mailtrap.io:2525" timed out.');
            }
        });
    }

    public function test_the_email_is_sent_and_only_then_acknowledged(): void
    {
        $this->channel->shouldReceive('basic_ack')->once()->with(99, false)->andReturnUsing(function () {
            // ACK comes after the send: the mail is already there.
            Mail::assertSent(OrderConfirmationMail::class, 1);
        });
        $this->channel->shouldNotReceive('basic_nack', 'basic_reject');

        $outcome = $this->consumer()->handle($this->delivery(json_encode($this->event())));

        $this->assertSame(DeliveryOutcome::Acked, $outcome);
        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) {
            return $mail->hasTo('lina@example.test')
                && $mail->event->orderId === 42
                && $mail->event->eventId === '6f1c2a8e-0000-4000-8000-000000000001';
        });
    }

    public function test_a_failed_send_is_never_acknowledged_but_returned_for_a_retry(): void
    {
        $this->failingMailer();
        Log::shouldReceive('channel')->with('order_email_worker')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
            return str_contains($message, 'returning the message for a retry')
                && $context['attempt'] === 1
                && $context['max_attempts'] === 4
                && $context['event_id'] === '6f1c2a8e-0000-4000-8000-000000000001'
                && $context['error_class'] === TransportException::class
                && str_contains($context['error'], 'timed out');
        });
        $this->channel->shouldNotReceive('basic_ack', 'basic_reject');
        // basic_nack(delivery_tag, multiple=false, requeue=true)
        $this->channel->shouldReceive('basic_nack')->once()->with(99, false, true);

        $this->assertSame(DeliveryOutcome::Retried, $this->consumer()->handle($this->delivery(json_encode($this->event()))));
    }

    public function test_the_last_attempt_is_also_nacked_so_the_broker_dead_letters_it(): void
    {
        $this->failingMailer();
        Log::shouldReceive('channel')->with('order_email_worker')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context) => $context['attempt'] === 4
            && str_contains($message, 'last attempt'));
        $this->channel->shouldNotReceive('basic_ack', 'basic_reject');
        $this->channel->shouldReceive('basic_nack')->once()->with(99, false, true);

        // Delivered 3 times before (x-delivery-count = 3): this is attempt 4 of 4.
        $this->consumer()->handle($this->delivery(json_encode($this->event()), deliveryCount: 3));
    }

    public function test_the_attempt_number_comes_from_the_brokers_delivery_count(): void
    {
        $this->assertSame(1, OrderCreatedEmailConsumer::attempt($this->delivery('{}')));
        $this->assertSame(3, OrderCreatedEmailConsumer::attempt($this->delivery('{}', deliveryCount: 2)));
    }

    public function test_an_unprocessable_message_is_rejected_straight_to_the_dlq(): void
    {
        $this->channel->shouldNotReceive('basic_ack', 'basic_nack');
        // basic_reject(delivery_tag, requeue=false)
        $this->channel->shouldReceive('basic_reject')->times(3)->with(99, false);

        foreach ([
            'not json',
            json_encode(['event_type' => 'order.cancelled']),
            json_encode($this->event(['data' => ['customer' => ['email' => 'not-an-email']]])),
        ] as $body) {
            $this->assertSame(DeliveryOutcome::Rejected, $this->consumer()->handle($this->delivery($body)));
        }

        Mail::assertNothingSent();
    }

    public function test_the_confirmation_email_shows_the_order(): void
    {
        $mail = new OrderConfirmationMail(OrderCreatedMessage::fromJson(json_encode($this->event())));

        $mail->assertHasSubject('Order #42 confirmation');
        $mail->assertSeeInHtml('Lina Customer');
        $mail->assertSeeInHtml('Desk Lamp');
        $mail->assertSeeInHtml('Notebook');
        $mail->assertSeeInHtml('25.00');
        $mail->assertSeeInHtml('Total: 28.00');
        $mail->assertSeeInHtml('6f1c2a8e-0000-4000-8000-000000000001');
        // A redelivered event produces the same Message-ID, so a duplicate is visible.
        $this->assertSame('6f1c2a8e-0000-4000-8000-000000000001@order-events.dukkan', $mail->headers()->messageId);
    }

    public function test_sends_are_spaced_by_the_configured_minimum_interval(): void
    {
        config(['messaging.topology.order_created_email.min_interval_ms' => 300]);
        $this->channel->shouldReceive('basic_ack')->twice();
        $consumer = $this->consumer();

        $start = microtime(true);
        $consumer->handle($this->delivery(json_encode($this->event())));
        $consumer->handle($this->delivery(json_encode($this->event())));

        $this->assertGreaterThanOrEqual(0.3, microtime(true) - $start);
        Mail::assertSent(OrderConfirmationMail::class, 2);
    }
}
