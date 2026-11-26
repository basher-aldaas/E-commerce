<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (Requirement 4). A row is written in the same database
 * transaction as the business change it describes (e.g. the order), so either
 * both exist or neither does. The outbox relay publishes unpublished rows to
 * RabbitMQ and sets published_at only after the broker confirmed the message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->string('type', 100);
            $table->string('aggregate_type', 50);
            $table->string('aggregate_id', 64);
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['published_at', 'available_at', 'id']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
