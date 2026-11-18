<?php

namespace Tests\Feature\Performance;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Products\Models\Product;
use Modules\Products\Services\CatalogCache;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * Read replica (Requirement 3).
 *
 * - Only the catalog cache reads through database.read_connection: on a miss
 *   and on every uncached catalog read (bypass, in_stock, cache off).
 * - A hit reads no database.
 * - The replica answers only once it contains the primary's GTID set taken
 *   after the latest catalog write; otherwise the primary answers.
 * - An unreachable replica falls back to the primary; a missing product (404)
 *   does not.
 * - Writes and every other read (auth, permissions, orders) use the primary.
 *
 * Tests run in one transaction on the default connection (RefreshDatabase), so
 * "the replica" here shares the primary's PDO: the tests check the routing, not
 * MySQL replication itself.
 */
class ReadReplicaRoutingTest extends ApiTestCase
{
    private Product $product;

    /** @var list<string> connection name of each query that ran */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['products.cache.enabled' => true, 'products.cache.allow_bypass_header' => true]);
        Cache::flush();

        $this->product = $this->makeProduct(['stock' => 10]);
        $this->actingAsApi($this->makeUser());
    }

    /** Points read_connection at a "replica" that shares the test transaction. */
    private function useReplica(): void
    {
        config(['database.read_connection' => 'mysql_replica']);
        $pdo = DB::connection()->getPdo();
        DB::connection('mysql_replica')->setPdo($pdo)->setReadPdo($pdo);
    }

    private function recordQueries(): void
    {
        $this->queries = [];
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'from `products`')) {
                $this->queries[] = $query->connectionName;
            }
        });
    }

    public function test_without_a_replica_catalog_reads_use_the_default_connection(): void
    {
        $this->recordQueries();

        $this->getJson('/api/v1/products')->assertOk()
            ->assertHeader('X-Cache', 'MISS')
            ->assertHeader('X-Read-Source', 'primary');

        $this->assertSame(['mysql'], array_unique($this->queries));
    }

    public function test_a_miss_reads_the_replica_and_a_hit_reads_no_database(): void
    {
        $this->useReplica();
        $this->recordQueries();

        $this->getJson('/api/v1/products')->assertOk()
            ->assertHeader('X-Cache', 'MISS')
            ->assertHeader('X-Read-Source', 'replica');
        $this->assertSame(['mysql_replica'], array_unique($this->queries));

        $this->queries = [];
        $this->getJson('/api/v1/products')->assertOk()
            ->assertHeader('X-Cache', 'HIT')
            ->assertHeaderMissing('X-Read-Source');
        $this->assertSame([], $this->queries);
    }

    public function test_a_bypass_and_the_uncached_in_stock_list_read_the_replica(): void
    {
        $this->useReplica();
        $this->recordQueries();

        $this->getJson('/api/v1/products', ['X-Cache-Bypass' => '1'])->assertOk()
            ->assertHeader('X-Cache', 'BYPASS')
            ->assertHeader('X-Read-Source', 'replica');
        $this->getJson('/api/v2/products?in_stock=1')->assertOk()
            ->assertHeader('X-Cache', 'SKIP')
            ->assertHeader('X-Read-Source', 'replica');

        $this->assertSame(['mysql_replica'], array_unique($this->queries));
    }

    public function test_a_replica_without_the_latest_catalog_write_is_not_read(): void
    {
        $this->useReplica();
        // A transaction the server has never executed: the replica is "behind".
        Cache::forever(CatalogCache::GTID_KEY, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa:1');
        $this->recordQueries();

        $this->getJson('/api/v1/products')->assertOk()
            ->assertHeader('X-Cache', 'MISS')
            ->assertHeader('X-Read-Source', 'primary');

        $this->assertSame(['mysql'], array_unique($this->queries));
    }

    public function test_a_catalog_write_forgets_the_gtid_and_moves_the_version(): void
    {
        $this->useReplica();
        $this->getJson('/api/v1/products')->assertOk();
        $this->assertNotNull(Cache::get(CatalogCache::GTID_KEY));
        $version = Cache::get(CatalogCache::VERSION_KEY);

        $this->product->update(['price' => 12.34]);

        $this->assertNull(Cache::get(CatalogCache::GTID_KEY));
        $this->assertSame($version + 1, (int) Cache::get(CatalogCache::VERSION_KEY));
        $this->getJson("/api/v1/products/{$this->product->id}")->assertOk()
            ->assertHeader('X-Cache', 'MISS')
            ->assertJsonPath('data.price', '12.34');
    }

    public function test_an_unreachable_replica_falls_back_to_the_primary(): void
    {
        Log::spy();
        config([
            'database.read_connection' => 'mysql_replica',
            'database.connections.mysql_replica.host' => 'replica.invalid',
        ]);
        DB::purge('mysql_replica');

        $this->getJson('/api/v1/products', ['X-Cache-Bypass' => '1'])->assertOk()
            ->assertHeader('X-Read-Source', 'primary-fallback');
        $this->getJson('/api/v1/products')->assertOk()
            ->assertHeader('X-Cache', 'MISS')
            ->assertHeader('X-Read-Source', 'primary-fallback');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'Read replica unavailable'))
            ->twice();
    }

    public function test_a_missing_product_is_a_404_not_a_fallback(): void
    {
        Log::spy();
        $this->useReplica();

        $this->getJson('/api/v1/products/999999')->assertNotFound();

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_writes_auth_and_orders_never_use_the_replica(): void
    {
        $this->useReplica();
        $used = [];
        DB::listen(function ($query) use (&$used) {
            $used[$query->connectionName] = true;
        });

        $this->makeUser(attributes: ['email' => 'reader@example.test']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'reader@example.test', 'password' => 'password123'])
            ->assertOk()->json('data.access_token');
        $this->app->make('auth')->forgetGuards();
        $this->withToken($token);
        $this->postOrder(['items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertStatus(201);
        $this->getJson('/api/v1/orders')->assertOk();

        $this->assertSame(['mysql'], array_keys($used));
    }

    /** Each replica session is READ ONLY: even pointed at the primary, it can't write. */
    public function test_the_replica_connection_refuses_writes(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('READ ONLY');

        try {
            DB::connection('mysql_replica')->update('update products set name = name where id = -1');
        } finally {
            DB::purge('mysql_replica');
        }
    }

    public function test_the_read_connection_follows_db_read_host(): void
    {
        $this->assertSame(env('DB_CONNECTION', 'sqlite'), $this->databaseConfigWith(null)['read_connection']);

        $config = $this->databaseConfigWith('mysql-replica');
        $this->assertSame('mysql_replica', $config['read_connection']);
        $this->assertSame('mysql-replica', $config['connections']['mysql_replica']['host']);
        $this->assertArrayNotHasKey('read', $config['connections']['mysql']);
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
