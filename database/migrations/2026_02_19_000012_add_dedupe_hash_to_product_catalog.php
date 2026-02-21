<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_catalog', function (Blueprint $table) {
            $table->string('normalized_title_hash', 64)->nullable()->after('normalized_text');
        });

        // Keep source bounded for index efficiency.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_catalog ALTER COLUMN source TYPE varchar(20)');
            DB::statement("ALTER TABLE product_catalog DROP CONSTRAINT IF EXISTS product_catalog_source_check");
            DB::statement("ALTER TABLE product_catalog ADD CONSTRAINT product_catalog_source_check CHECK (source IN ('sold','asking','estimate'))");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS product_catalog_dedupe_unique ON product_catalog (client_id, source, normalized_title_hash, price, currency, COALESCE(sold_at, TIMESTAMP '1970-01-01 00:00:00'))");
        } else {
            Schema::table('product_catalog', function (Blueprint $table) {
                $table->unique(['client_id', 'source', 'normalized_title_hash', 'price', 'currency', 'sold_at'], 'product_catalog_dedupe_unique');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS product_catalog_dedupe_unique');
            DB::statement('ALTER TABLE product_catalog DROP CONSTRAINT IF EXISTS product_catalog_source_check');
        } else {
            Schema::table('product_catalog', function (Blueprint $table) {
                $table->dropUnique('product_catalog_dedupe_unique');
            });
        }

        Schema::table('product_catalog', function (Blueprint $table) {
            $table->dropColumn('normalized_title_hash');
        });
    }
};
