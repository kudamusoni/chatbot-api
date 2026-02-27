<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('email');
            $table->char('email_hash', 64);
            $table->string('role');
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('invited_by_user_id');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('invited_by_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['client_id', 'email_hash']);
            $table->index(['client_id', 'accepted_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_invitations ADD CONSTRAINT client_invitations_role_check CHECK (role IN ('admin','viewer'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invitations');
    }
};
