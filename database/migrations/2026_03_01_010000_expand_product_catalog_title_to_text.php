<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Auction/catalog titles can exceed 255 chars.
        DB::statement('ALTER TABLE product_catalog ALTER COLUMN title TYPE TEXT');
    }

    public function down(): void
    {
        // Truncate to keep rollback valid when long rows exist.
        DB::statement('ALTER TABLE product_catalog ALTER COLUMN title TYPE VARCHAR(255) USING LEFT(title, 255)');
    }
};
