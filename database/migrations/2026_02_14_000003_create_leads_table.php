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
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('client_id');
            $table->unsignedBigInteger('request_event_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('phone_raw');
            $table->string('phone_normalized');
            $table->string('status')->default('REQUESTED');
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('request_event_id')->references('id')->on('conversation_events')->nullOnDelete();

            $table->index(['client_id', 'created_at']);
            $table->index('conversation_id');
            $table->unique('request_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
