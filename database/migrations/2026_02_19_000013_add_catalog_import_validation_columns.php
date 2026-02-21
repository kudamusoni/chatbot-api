<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_imports', function (Blueprint $table) {
            $table->json('validated_header')->nullable()->after('mapping');
            $table->timestamp('queued_at')->nullable()->after('errors_sample');
            $table->string('file_hash', 64)->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_imports', function (Blueprint $table) {
            $table->dropColumn(['validated_header', 'queued_at', 'file_hash']);
        });
    }
};
