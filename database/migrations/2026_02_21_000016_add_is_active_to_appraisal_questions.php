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
        Schema::table('appraisal_questions', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('order_index');
            $table->index(['client_id', 'is_active', 'order_index'], 'appraisal_questions_client_active_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appraisal_questions', function (Blueprint $table) {
            $table->dropIndex('appraisal_questions_client_active_order_idx');
            $table->dropColumn('is_active');
        });
    }
};
