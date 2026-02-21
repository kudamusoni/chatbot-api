<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_imports', function (Blueprint $table) {
            $table->unsignedInteger('attempt')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_imports', function (Blueprint $table) {
            $table->dropColumn('attempt');
        });
    }
};
