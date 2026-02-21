<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_user')->where('role', 'member')->update(['role' => 'viewer']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_user ALTER COLUMN role SET DEFAULT 'viewer'");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE client_user MODIFY role VARCHAR(255) NOT NULL DEFAULT 'viewer'");
        } elseif (DB::getDriverName() === 'sqlite') {
            // SQLite cannot alter column defaults in-place; tests using sqlite
            // should rely on explicit role values or prior normalized data.
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_user DROP CONSTRAINT IF EXISTS client_user_role_check");
            DB::statement("ALTER TABLE client_user ADD CONSTRAINT client_user_role_check CHECK (role IN ('owner','admin','viewer','member'))");
        }

        Schema::table('client_user', function (Blueprint $table) {
            $table->index(['client_id', 'user_id'], 'client_user_client_id_user_id_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_user ALTER COLUMN role SET DEFAULT 'member'");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE client_user MODIFY role VARCHAR(255) NOT NULL DEFAULT 'member'");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_user DROP CONSTRAINT IF EXISTS client_user_role_check");
        }

        Schema::table('client_user', function (Blueprint $table) {
            $table->dropIndex('client_user_client_id_user_id_idx');
        });
    }
};
