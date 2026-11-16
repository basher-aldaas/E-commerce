<?php

namespace Tests\Feature\Performance;

use App\Support\Database\ReadRouting;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Products\Models\Product;
use Modules\Products\Services\CatalogCache;
use RedisException;
use ReflectionProperty;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * Read/write split with read-your-writes (Phase 4, Requirement 3).
 *
 * - Every read goes to the primary by default (orders, reservations, auth...).
 * - Catalog GET routes may read from the replica...
 * - ...except for a user who wrote something in the last few seconds.
 * - Catalog-cache refills always read from the primary, so a lagging replica can
 *   never put stale data into Redis.
 */
class ReadReplicaRoutingTest extends ApiTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->makeProduct(['stock' => 10]);
    }

    private function routing(): ReadRouting
    {
        return $this->app->make(ReadRouting::class);
    }

    private function connectionReadsFromPrimary(): bool
    {
        return (new ReflectionProperty(DB::connection(), 'readOnWriteConnection'))->getValue(DB::connection());
    }

    private function placeOrder(): void
    {
        $this->postOrder(['items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertStatus(201);
    }

    public function test_order_reads_use_the_primary(): void
    {
        $this->actingAsApi($this->makeUser());
        $this->getJson('/api/v1/orders')->assertOk();

        $this->assertFalse($this->routing()->replicaAllowed());
        $this->assertTrue($this->connectionReadsFromPrimary());
    }

    public function test_catalog_reads_may_use_the_replica(): void
    {
        $this->actingAsApi($this->makeUser());
        $this->getJson('/api/v2/products')->assertOk();

        $this->assertTrue($this->routing()->replicaAllowed());
        $this->assertFalse($this->connectionReadsFromPrimary());
    }

    public function test_a_user_who_just_wrote_reads_the_catalog_from_the_primary(): void
    {
        $user = $this->makeUser();
        $this->actingAsApi($user);
        $this->placeOrder();

        $this->actingAsApi($user);
        $this->getJson('/api/v2/products')->assertOk();

        $this->assertFalse($this->routing()->replicaAllowed());
    }

    public function test_someone_elses_write_does_not_affect_other_users(): void
    {
        $this->actingAsApi($this->makeUser());
        $this->placeOrder();

        $this->actingAsApi($this->makeUser());
        $this->getJson('/api/v2/products')->assertOk();

        $this->assertTrue($this->routing()->replicaAllowed());
    }

    public function test_the_read_your_writes_window_expires(): void
    {
        config(['database.read_your_writes_seconds' => 5]);
        $user = $this->makeUser();
        $this->actingAsApi($user);
        $this->placeOrder();

        $this->travel(6)->seconds();
        $this->actingAsApi($user);
        $this->getJson('/api/v2/products')->assertOk();

        $this->assertTrue($this->routing()->replicaAllowed());
    }

    public function test_a_failed_write_leaves_no_marker(): void
    {
        $user = $this->makeUser();
        $this->actingAsApi($user);
        $this->postOrder(['items' => []])->assertStatus(422);

        $this->actingAsApi($user);
        $this->getJson('/api/v2/products')->assertOk();

        $this->assertTrue($this->routing()->replicaAllowed());
    }

    /**
     * With a real JWT, the user lookup (auth:api) and the permission checks run
     * before the controller. They must read the primary; only the controller's
     * catalog query may use the replica.
     */
    public function test_auth_and_permission_lookups_read_the_primary_with_a_real_token(): void
    {
        $this->makeUser(attributes: ['email' => 'realtoken@example.test']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'realtoken@example.test', 'password' => 'password123'])
            ->assertOk()->json('data.access_token');
        $this->app->make('auth')->forgetGuards();

        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            $seen[] = [$query->sql, $this->connectionReadsFromPrimary()];
        });

        // Bypass the catalog cache: a cache refill would (correctly) read the primary.
        $this->withToken($token)->withHeader('X-Cache-Bypass', '1')->getJson('/api/v2/products')->assertOk()->assertHeader('X-Cache', 'BYPASS');

        $onPrimary = fn (string $table) => collect($seen)->filter(fn ($q) => str_contains($q[0], "from `{$table}`"));
        $this->assertNotEmpty($onPrimary('users'), 'the JWT user lookup did not run');
        foreach (['users', 'model_has_roles', 'roles', 'permissions', 'role_has_permissions', 'model_has_permissions'] as $table) {
            foreach ($onPrimary($table) as [$sql, $primary]) {
                $this->assertTrue($primary, "read on the replica: {$sql}");
            }
        }
        $productReads = $onPrimary('products');
        $this->assertNotEmpty($productReads);
        $this->assertFalse($productReads->first()[1], 'the catalog query should be allowed on the replica');
    }

    /** Console commands, the scheduler and workers run no HTTP middleware. */
    public function test_every_new_connection_reads_the_primary_by_default(): void
    {
        config(['database.connections.probe' => config('database.connections.mysql')]);

        $connection = DB::connection('probe');

        $this->assertTrue((new ReflectionProperty($connection, 'readOnWriteConnection'))->getValue($connection));
        DB::purge('probe');
    }

    public function test_signing_in_is_not_a_write(): void
    {
        $user = $this->makeUser(attributes: ['email' => 'reader@example.test']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'reader@example.test', 'password' => 'password123'])
            ->assertOk()->json('data.access_token');

        $this->withToken($token)->getJson('/api/v2/products')->assertOk();

        $this->assertFalse($this->app->make(ReadRouting::class)->hasRecentWrite($user->id));
        $this->assertTrue($this->routing()->replicaAllowed());
    }

    /** Makes every write of a read-your-writes marker fail, as when Redis goes down. */
    private function failMarkerWrites(): void
    {
        Cache::extend('marker-fails', fn () => Cache::repository(new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                if (str_starts_with($key, 'db:recent-write:')) {
                    throw new RedisException('Connection refused');
                }

                return parent::put($key, $value, $seconds);
            }
        }));
        config(['cache.stores.marker-fails' => ['driver' => 'marker-fails']]);
        Cache::setDefaultDriver('marker-fails');
    }

    /**
     * The order is committed before the marker is written. If Redis fails at that
     * moment the order still exists, so the customer must get it (201), the failure
     * must be logged, and a retry with the same key must return the same order.
     */
    public function test_a_committed_order_succeeds_when_the_marker_cannot_be_written(): void
    {
        Log::spy();
        $this->failMarkerWrites();
        $user = $this->makeUser();
        $payload = ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'cart-1'];

        $this->actingAsApi($user);
        $first = $this->postOrder($payload, $headers);

        $this->assertSame(201, $first->status(), $first->getContent());
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'read-your-writes marker')
            && $context['user_id'] === $user->id
            && $context['exception'] instanceof RedisException)->once();

        $this->actingAsApi($user);
        $retry = $this->postOrder($payload, $headers);

        $this->assertSame(201, $retry->status(), $retry->getContent());
        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, $this->warehouse->callsTo('reserve'));
        $this->assertSame(9, $this->warehouse->stock[$this->product->id]);
    }

    /**
     * Before the fix the client got a 500 although the order was committed. That is
     * the same as a lost reply: the client retries with the same key and gets the
     * order that exists, never a second one.
     */
    public function test_a_retry_after_a_lost_reply_returns_the_existing_order(): void
    {
        $user = $this->makeUser();
        $payload = ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'cart-1'];
        $this->actingAsApi($user);
        $orderId = $this->postOrder($payload, $headers)->assertStatus(201)->json('data.id');

        foreach (range(1, 3) as $retry) {
            $this->actingAsApi($user);
            $this->postOrder($payload, $headers)->assertStatus(201)->assertJsonPath('data.id', $orderId);
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_reservations', 1);
        $this->assertCount(1, $this->warehouse->callsTo('reserve'));
    }

    public function test_cache_refills_always_read_from_the_primary(): void
    {
        config(['products.cache.enabled' => true]);
        $routing = $this->routing();
        $routing->allowReplica();
        $duringLoad = null;

        $this->app->make(CatalogCache::class)->remember('test:key', function () use ($routing, &$duringLoad) {
            $duringLoad = $routing->replicaAllowed();

            return 'value';
        });

        $this->assertFalse($duringLoad, 'the refill ran on the replica');
        $this->assertTrue($routing->replicaAllowed(), 'the request did not get the replica back');
    }

    public function test_without_a_replica_host_there_is_no_read_write_split(): void
    {
        $config = $this->databaseConfigWith(null);

        $this->assertArrayNotHasKey('read', $config['connections']['mysql']);
    }

    public function test_a_replica_host_enables_the_split_with_sticky_writes(): void
    {
        $config = $this->databaseConfigWith('mysql-replica');
        $mysql = $config['connections']['mysql'];

        $this->assertSame(['host' => ['mysql-replica']], $mysql['read']);
        $this->assertSame(['host' => [env('DB_HOST', '127.0.0.1')]], $mysql['write']);
        $this->assertTrue($mysql['sticky']);
    }

    private function databaseConfigWith(?string $readHost): array
    {
        $previous = getenv('DB_READ_HOST');
        $readHost === null ? putenv('DB_READ_HOST') : putenv("DB_READ_HOST={$readHost}");
        $_ENV['DB_READ_HOST'] = $_SERVER['DB_READ_HOST'] = $readHost;

        try {
            return require config_path('database.php');
        } finally {
            $previous === false ? putenv('DB_READ_HOST') : putenv("DB_READ_HOST={$previous}");
            unset($_ENV['DB_READ_HOST'], $_SERVER['DB_READ_HOST']);
        }
    }
}
