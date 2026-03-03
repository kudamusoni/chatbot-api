<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->string('preflight_status', 20)->nullable()->after('input_snapshot');
            $table->json('preflight_details')->nullable()->after('preflight_status');
            $table->decimal('confidence_cap', 4, 3)->nullable()->after('preflight_details');
        });
    }

    public function down(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->dropColumn(['preflight_status', 'preflight_details', 'confidence_cap']);
        });
    }
};
