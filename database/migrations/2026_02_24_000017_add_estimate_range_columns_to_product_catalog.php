<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_catalog', function (Blueprint $table) {
            $table->integer('low_estimate')->nullable()->after('price');
            $table->integer('high_estimate')->nullable()->after('low_estimate');
        });
    }

    public function down(): void
    {
        Schema::table('product_catalog', function (Blueprint $table) {
            $table->dropColumn(['low_estimate', 'high_estimate']);
        });
    }
};
