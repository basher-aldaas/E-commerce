<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('reservation_key')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->char('idempotency_key', 64)->nullable();// هاش مفتاح السابق يلي اجا مع اليدر اذا ما كان في بكون نل
            $table->char('request_hash', 64);// هاش الطلب الحالي

            $table->string('status')->index();

            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_reservations');
    }
};
