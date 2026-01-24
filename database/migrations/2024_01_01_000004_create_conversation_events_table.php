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
        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id(); // BIGINT auto-increment (monotonic for SSE replay)
            $table->uuid('conversation_id');
            $table->uuid('client_id');
            $table->string('type');
            $table->json('payload'); // NOT NULL - events must always have payload
            $table->uuid('correlation_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('created_at')->useCurrent(); // Immutable: only created_at, no updated_at

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();

            // Hot path for SSE replay
            $table->index(['conversation_id', 'id']);
            // Admin analytics
            $table->index(['client_id', 'created_at']);
            // DB-enforced idempotency (MySQL/Postgres allows multiple NULLs)
            $table->unique(['conversation_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
    }
};
