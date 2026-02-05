<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable pg_trgm extension for trigram-based text search
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('product_catalog', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source'); // sold, asking, estimate
            $table->integer('price'); // in cents/pence for precision
            $table->string('currency', 3)->default('GBP');
            $table->timestamp('sold_at')->nullable();
            $table->text('normalized_text'); // lowercase title+description for search
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();

            // Indexes for common queries
            $table->index(['client_id', 'source']);
            $table->index(['client_id', 'sold_at']);
            $table->index(['client_id', 'currency']);
        });

        // Create trigram index for fast ILIKE search
        DB::statement('
            CREATE INDEX product_catalog_normalized_text_trgm 
            ON product_catalog USING gin (normalized_text gin_trgm_ops)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_catalog');
    }
};
