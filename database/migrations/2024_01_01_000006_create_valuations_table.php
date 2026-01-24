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
        Schema::create('valuations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('client_id');
            $table->unsignedBigInteger('request_event_id')->nullable();
            $table->string('status')->default('PENDING');
            $table->string('snapshot_hash', 64);
            $table->json('input_snapshot');
            $table->json('result')->nullable();
            $table->timestamps(); // Mutable projection: uses full timestamps

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('request_event_id')->references('id')->on('conversation_events')->nullOnDelete();

            // Idempotent valuations per conversation + snapshot
            $table->unique(['conversation_id', 'snapshot_hash']);
            $table->index(['client_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('valuations');
    }
};
