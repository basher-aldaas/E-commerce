<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
