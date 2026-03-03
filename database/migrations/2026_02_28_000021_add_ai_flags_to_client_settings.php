<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_settings', function (Blueprint $table): void {
            if (!Schema::hasColumn('client_settings', 'ai_enabled')) {
                $table->boolean('ai_enabled')->default(false)->after('widget_security_version');
            }

            if (!Schema::hasColumn('client_settings', 'ai_normalization_enabled')) {
                $table->boolean('ai_normalization_enabled')->default(false)->after('ai_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('client_settings', 'ai_normalization_enabled')) {
                $table->dropColumn('ai_normalization_enabled');
            }

            if (Schema::hasColumn('client_settings', 'ai_enabled')) {
                $table->dropColumn('ai_enabled');
            }
        });
    }
};

