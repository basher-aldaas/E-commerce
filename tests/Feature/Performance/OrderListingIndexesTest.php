<?php

namespace Tests\Feature\Performance;

use Illuminate\Support\Facades\Schema;
use Tests\Feature\Requirement1\ApiTestCase;

/**
 * The order lists sort by created_at DESC, id DESC (OrderService::getOrders).
 * These indexes let MySQL read the newest rows in order instead of sorting the
 * whole table (admin) or all of a customer's orders (customer).
 */
class OrderListingIndexesTest extends ApiTestCase
{
    private function indexColumns(string $table): array
    {
        return collect(Schema::getIndexes($table))->mapWithKeys(fn ($i) => [$i['name'] => $i['columns']])->all();
    }

    public function test_orders_have_the_listing_indexes(): void
    {
        $indexes = $this->indexColumns('orders');

        $this->assertSame(['created_at', 'id'], $indexes['orders_created_at_id_index'] ?? null);
        $this->assertSame(['user_id', 'created_at', 'id'], $indexes['orders_user_id_created_at_id_index'] ?? null);
    }

    public function test_user_id_stays_indexed_for_its_foreign_key(): void
    {
        $leading = collect($this->indexColumns('orders'))->map(fn ($columns) => $columns[0]);

        $this->assertContains('user_id', $leading->all());
    }
}
