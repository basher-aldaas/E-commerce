<?php

namespace Tests\Feature\Requirement4;

use App\Messaging\Outbox\Outbox;
use App\Messaging\Outbox\OutboxMessage;
use App\Messaging\Outbox\OutboxPublisher;
use App\Messaging\Outbox\OutboxRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Exception\AMQPIOException;
use Tests\Support\FakeOutboxPublisher;
use Tests\TestCase;

/**
 * Requirement 4: the outbox relay publishes stored events and marks a row as
 * published only when the broker confirmed it; nothing is ever dropped.
 */
class OutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    private FakeOutboxPublisher $broker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broker = new FakeOutboxPublisher();
        $this->app->instance(OutboxPublisher::class, $this->broker);
    }

    private function storeEvent(int $orderId): OutboxMessage
    {
        return DB::transaction(fn () => app(Outbox::class)->record('order.created', 'order', $orderId, ['order' => ['id' => $orderId]]));
    }

    private function relay(): array
    {
        return app(OutboxRelay::class)->relayBatch();
    }

    public function test_pending_events_are_published_in_order_and_marked(): void
    {
        $first = $this->storeEvent(1);
        $second = $this->storeEvent(2);

        $this->assertSame(['published' => 2, 'failed' => 0], $this->relay());

        $this->assertSame([$first->message_id, $second->message_id], array_column($this->broker->published, 'message_id'));
        $this->assertSame('order.created', $this->broker->published[0]['type']);
        // MySQL's JSON type reorders keys, so compare content, not key order.
        $this->assertEquals($first->refresh()->payload, json_decode($this->broker->published[0]['body'], true));

        foreach ([$first, $second] as $event) {
            $event->refresh();
            $this->assertNotNull($event->published_at);
            $this->assertSame(1, $event->attempts);
            $this->assertNull($event->last_error);
        }
    }

    public function test_published_events_are_not_sent_again(): void
    {
        $this->storeEvent(1);
        $this->relay();

        $this->assertSame(['published' => 0, 'failed' => 0], $this->relay());
        $this->assertCount(1, $this->broker->published);
    }

    public function test_an_event_the_broker_did_not_confirm_stays_unpublished_with_its_error_and_a_backoff(): void
    {
        $failed = $this->storeEvent(1);
        $ok = $this->storeEvent(2);
        $this->broker->failures[$failed->message_id] = 'returned by the broker: 312 NO_ROUTE';

        $this->assertSame(['published' => 1, 'failed' => 1], $this->relay());

        $failed->refresh();
        $this->assertNull($failed->published_at);
        $this->assertSame(1, $failed->attempts);
        $this->assertSame('returned by the broker: 312 NO_ROUTE', $failed->last_error);
        $this->assertTrue($failed->available_at->isFuture(), 'A failed row waits before the next attempt.');
        $this->assertNotNull($ok->refresh()->published_at);

        // Still waiting: not retried before its backoff ends...
        unset($this->broker->failures[$failed->message_id]);
        $this->assertSame(['published' => 0, 'failed' => 0], $this->relay());

        // ...and published once it is due.
        $this->travel(61)->seconds();
        $this->assertSame(['published' => 1, 'failed' => 0], $this->relay());
        $this->assertNotNull($failed->refresh()->published_at);
        $this->assertSame(2, $failed->attempts);
    }

    public function test_an_unreachable_broker_loses_nothing(): void
    {
        $event = $this->storeEvent(1);
        $this->broker->unreachable = new AMQPIOException('Connection refused');

        $result = $this->relay();

        $this->assertSame(0, $result['published']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('Connection refused', $result['error']);
        $event->refresh();
        $this->assertNull($event->published_at);
        $this->assertStringContainsString('Connection refused', $event->last_error);

        // Broker back: the same event goes out.
        $this->broker->unreachable = null;
        $this->travel(61)->seconds();
        $this->assertSame(['published' => 1, 'failed' => 0], $this->relay());
        $this->assertSame($event->message_id, $this->broker->published[0]['message_id']);
    }

    public function test_the_backoff_grows_and_is_capped(): void
    {
        config(['messaging.outbox.max_backoff_seconds' => 10]);
        $event = $this->storeEvent(1);
        $this->broker->failures[$event->message_id] = 'nack';

        $delays = [];
        for ($i = 0; $i < 5; $i++) {
            // The column stores whole seconds: freeze on one to measure exactly.
            $this->travelTo(now()->addSeconds(11)->startOfSecond());
            $this->freezeTime();
            $now = now()->copy();
            $this->relay();
            $delays[] = (int) round($now->diffInSeconds($event->refresh()->available_at));
        }

        $this->assertSame([2, 4, 8, 10, 10], $delays);
    }

    public function test_the_relay_command_publishes_one_batch_with_once(): void
    {
        $this->storeEvent(7);

        $this->artisan('outbox:relay', ['--once' => true])
            ->expectsOutputToContain('published=1 failed=0')
            ->assertSuccessful();

        $this->assertCount(1, $this->broker->published);
    }
}
