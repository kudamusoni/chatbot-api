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
            $table->timestamp('sold_at_key')->nullable()->after('sold_at');
        });

        DB::table('product_catalog')->update([
            'sold_at_key' => DB::raw("COALESCE(sold_at, TIMESTAMP '1970-01-01 00:00:00')"),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE product_catalog ALTER COLUMN sold_at_key SET DEFAULT TIMESTAMP '1970-01-01 00:00:00'");
            DB::statement('ALTER TABLE product_catalog ALTER COLUMN sold_at_key SET NOT NULL');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS product_catalog_dedupe_unique');
        } else {
            Schema::table('product_catalog', function (Blueprint $table) {
                $table->dropUnique('product_catalog_dedupe_unique');
            });
        }

        Schema::table('product_catalog', function (Blueprint $table) {
            $table->unique(['client_id', 'source', 'normalized_title_hash', 'price', 'currency', 'sold_at_key'], 'product_catalog_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_catalog', function (Blueprint $table) {
            $table->dropUnique('product_catalog_dedupe_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS product_catalog_dedupe_unique ON product_catalog (client_id, source, normalized_title_hash, price, currency, COALESCE(sold_at, TIMESTAMP '1970-01-01 00:00:00'))");
        } else {
            Schema::table('product_catalog', function (Blueprint $table) {
                $table->unique(['client_id', 'source', 'normalized_title_hash', 'price', 'currency', 'sold_at'], 'product_catalog_dedupe_unique');
            });
        }

        Schema::table('product_catalog', function (Blueprint $table) {
            $table->dropColumn('sold_at_key');
        });
    }
};
