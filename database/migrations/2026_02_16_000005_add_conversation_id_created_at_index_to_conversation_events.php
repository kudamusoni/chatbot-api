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
        Schema::table('conversation_events', function (Blueprint $table) {
            $table->index(['conversation_id', 'created_at'], 'conversation_events_conv_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_events', function (Blueprint $table) {
            $table->dropIndex('conversation_events_conv_created_idx');
        });
    }
};
