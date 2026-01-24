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
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('session_token_hash', 64);
            $table->string('state')->default('CHAT');
            $table->json('context')->nullable();
            // Future-proof: SSE resume and admin sorting
            $table->unsignedBigInteger('last_event_id')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps(); // Mutable projection: uses full timestamps

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->index(['client_id', 'created_at']);
            $table->index('last_event_id');
            $table->index('last_activity_at');
            // Token uniqueness per client (not global)
            $table->unique(['client_id', 'session_token_hash']);
            // Fast token lookup
            $table->index('session_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
