<?php

namespace Tests\Feature\Warehouse;

use App\Exceptions\Warehouse\WarehouseUnavailableException;
use Illuminate\Testing\TestResponse;
use Modules\Orders\Models\OrderReservation;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * GET /api/v1/system/reservations: Laravel's own workflow record of every order
 * attempt, for admins. Read-only; it never exposes reservation keys or hashes.
 */
class ReservationDiagnosticsTest extends ApiTestCase
{
    private Product $product;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->makeProduct(['stock' => 10]);
        $this->customer = $this->makeUser('customer', ['name' => 'Sara Ali']);
    }

    private function placeOrder(int $quantity = 1, array $headers = []): TestResponse
    {
        $this->actingAsApi($this->customer);

        return $this->postOrder([
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ], $headers);
    }

    private function list(string $query = ''): TestResponse
    {
        $this->actingAsApi($this->makeUser('admin'));

        return $this->getJson('/api/v1/system/reservations'.$query);
    }

    public function test_lists_attempts_with_and_without_orders(): void
    {
        $this->placeOrder()->assertStatus(201);
        $this->placeOrder(50)->assertStatus(409);

        $response = $this->list();

        $this->assertEnvelope($response, 200);
        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        // Newest first.
        $this->assertSame('rejected', $items[0]['status']);
        $this->assertNull($items[0]['order_id']);
        $this->assertSame('placed', $items[1]['status']);
        $this->assertNotNull($items[1]['order_id']);
        $this->assertSame(['id' => $this->customer->id, 'name' => 'Sara Ali'], $items[1]['user']);
        $this->assertSame(
            ['id', 'status', 'warehouse_expectation', 'order_id', 'user', 'idempotency_fp', 'created_at', 'updated_at'],
            array_keys($items[0]),
        );
    }

    public function test_fingerprint_is_the_first_8_characters_of_the_stored_hash(): void
    {
        $this->placeOrder(1, ['Idempotency-Key' => 'cart-1'])->assertStatus(201);

        $this->list()->assertJsonPath('data.items.0.idempotency_fp', substr(hash('sha256', 'cart-1'), 0, 8));
    }

    /** Orders placed before the Idempotency-Key header became required have none. */
    public function test_the_fingerprint_is_null_without_a_key(): void
    {
        OrderReservation::create([
            'reservation_key' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'user_id' => $this->customer->id,
            'idempotency_key' => null,
            'request_hash' => hash('sha256', json_encode([$this->product->id => 1])),
            'status' => OrderReservation::REJECTED,
        ]);

        $this->list()->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.idempotency_fp', null);
    }

    public function test_never_exposes_keys_or_hashes(): void
    {
        $this->placeOrder(1, ['Idempotency-Key' => 'cart-1'])->assertStatus(201);
        $reservation = OrderReservation::sole();

        $body = $this->list()->assertOk()->assertJsonCount(1, 'data.items')->getContent();

        $this->assertStringNotContainsString($reservation->reservation_key, $body);
        $this->assertStringNotContainsString($reservation->idempotency_key, $body);
        $this->assertStringNotContainsString($reservation->request_hash, $body);
        $this->assertStringNotContainsString('cart-1', $body);
        $this->assertStringNotContainsString('reservation_key', $body);
        $this->assertStringNotContainsString('request_hash', $body);
    }

    public static function expectations(): array
    {
        return [
            ['pending', 'unknown'],
            ['rejected', 'not_held'],
            ['placed', 'held'],
            ['release_pending', 'unsettled'],
            ['released', 'not_held'],
            ['cancel_pending', 'unsettled'],
            ['cancelled', 'not_held'],
            ['attention', 'unknown'],
        ];
    }

    #[DataProvider('expectations')]
    public function test_each_state_maps_to_what_the_warehouse_is_expected_to_hold(string $status, string $expected): void
    {
        $this->placeOrder()->assertStatus(201);
        OrderReservation::query()->update(['status' => $status]);

        $this->list()->assertJsonPath('data.items.0.warehouse_expectation', $expected);
    }

    public function test_filters_by_status_and_counts_every_status(): void
    {
        $this->placeOrder()->assertStatus(201);
        $this->placeOrder()->assertStatus(201);
        $this->warehouse->failNext('reserve', WarehouseUnavailableException::beforeSending());
        $this->placeOrder()->assertStatus(503);

        $response = $this->list('?status=rejected');

        $this->assertCount(1, $response->json('data.items'));
        $response->assertJsonPath('data.items.0.status', 'rejected')
            ->assertJsonPath('data.counts', ['placed' => 2, 'rejected' => 1])
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_an_unknown_status_filter_is_rejected(): void
    {
        $this->list('?status=bogus')->assertStatus(422);
    }

    public function test_pages_hold_15_items(): void
    {
        $this->warehouse->stock[$this->product->id] = 20;

        for ($i = 0; $i < 16; $i++) {
            $this->placeOrder()->assertStatus(201);
        }

        $this->assertCount(15, $this->list()->json('data.items'));
        $this->assertCount(1, $this->list('?page=2')->json('data.items'));
    }

    public function test_only_admins_can_list(): void
    {
        $this->actingAsApi($this->customer);
        $this->getJson('/api/v1/system/reservations')->assertStatus(403);
    }

    public function test_the_response_is_never_cached(): void
    {
        $this->assertStringContainsString('no-store', (string) $this->list()->headers->get('Cache-Control'));
    }
}
