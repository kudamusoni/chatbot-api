<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Makes message projection idempotent by ensuring each event
     * can only produce one message row.
     */
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            // Unique constraint for idempotent projections
            // Each event can only create one message
            $table->unique(['conversation_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'event_id']);
        });
    }
};
