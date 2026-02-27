<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_user')->where('role', 'member')->update(['role' => 'viewer']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_user DROP CONSTRAINT IF EXISTS client_user_role_check");
            DB::statement("ALTER TABLE client_user ADD CONSTRAINT client_user_role_check CHECK (role IN ('owner','admin','viewer','member'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_user DROP CONSTRAINT IF EXISTS client_user_role_check");
            DB::statement("ALTER TABLE client_user ADD CONSTRAINT client_user_role_check CHECK (role IN ('owner','admin','viewer','member'))");
        }
    }
};
