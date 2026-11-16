<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (Requirement 3): indexes for the order lists.
 *
 * OrderService::getOrders sorts by created_at DESC, id DESC and pages by 15.
 * Without an index MySQL scans and sorts every order (admin: 200k rows, see
 * docs/performance/baseline/explain.txt) or all of one customer's orders.
 * With these, it reads the 15 newest index entries and stops.
 *
 * Kept as its own migration so the live demo can switch it off and on:
 *   php artisan migrate:rollback --step=1   (before)
 *   php artisan migrate                     (after)
 * MySQL drops the foreign key's own index (orders_user_id_foreign) once the
 * composite index, which also starts with user_id, can serve the key. down()
 * therefore re-creates it first; MySQL refuses to drop an index a foreign key
 * still needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['created_at', 'id'], 'orders_created_at_id_index');
            $table->index(['user_id', 'created_at', 'id'], 'orders_user_id_created_at_id_index');
        });
    }

    public function down(): void
    {
        $hasForeignKeyIndex = collect(Schema::getIndexes('orders'))->contains('name', 'orders_user_id_foreign');

        Schema::table('orders', function (Blueprint $table) use ($hasForeignKeyIndex) {
            if (! $hasForeignKeyIndex) {
                $table->index('user_id', 'orders_user_id_foreign');
            }
            $table->dropIndex('orders_created_at_id_index');
            $table->dropIndex('orders_user_id_created_at_id_index');
        });
    }
};
