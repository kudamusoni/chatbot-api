<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('bot_name')->nullable();
            $table->json('colors')->nullable();
            $table->json('prompt_settings')->nullable();
            $table->json('business_details')->nullable();
            $table->json('urls')->nullable();
            $table->boolean('widget_enabled')->default(true);
            $table->json('allowed_origins')->nullable();
            $table->unsignedInteger('widget_security_version')->default(1);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->index('client_id');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('client_id')->nullable();
            $table->string('action');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('actor_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->index('actor_user_id');
            $table->index('client_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('client_settings');
    }
};
