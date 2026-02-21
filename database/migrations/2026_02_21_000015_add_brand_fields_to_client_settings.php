<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_settings', function (Blueprint $table) {
            $table->string('brand_color')->nullable()->after('bot_name');
            $table->string('accent_color')->nullable()->after('brand_color');
            $table->string('logo_url')->nullable()->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('client_settings', function (Blueprint $table) {
            $table->dropColumn(['brand_color', 'accent_color', 'logo_url']);
        });
    }
};
