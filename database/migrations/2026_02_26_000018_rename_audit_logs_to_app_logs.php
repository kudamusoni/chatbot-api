<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs') && !Schema::hasTable('app_logs')) {
            Schema::rename('audit_logs', 'app_logs');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_logs') && !Schema::hasTable('audit_logs')) {
            Schema::rename('app_logs', 'audit_logs');
        }
    }
};

