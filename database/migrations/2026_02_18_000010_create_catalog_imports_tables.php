<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->unsignedBigInteger('created_by');
            $table->string('status')->default('CREATED');
            $table->string('file_path')->nullable();
            $table->json('mapping')->nullable();
            $table->json('totals')->nullable();
            $table->unsignedInteger('errors_count')->default(0);
            $table->json('errors_sample')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['client_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('catalog_import_errors', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_id');
            $table->unsignedInteger('row_number');
            $table->string('column')->nullable();
            $table->text('message');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->foreign('import_id')->references('id')->on('catalog_imports')->cascadeOnDelete();
            $table->index('import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_errors');
        Schema::dropIfExists('catalog_imports');
    }
};
