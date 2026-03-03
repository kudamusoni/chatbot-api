<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('event_id')->nullable();
            $table->string('purpose', 24);
            $table->string('provider', 32);
            $table->string('model', 120);
            $table->string('prompt_version', 40);
            $table->string('policy_version', 40)->nullable();
            $table->char('prompt_hash', 64);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cost_estimate_minor')->nullable();
            $table->string('status', 24);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('conversation_events')->nullOnDelete();

            $table->index(['client_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ai_requests ADD CONSTRAINT ai_requests_purpose_check CHECK (purpose IN ('CHAT','NORMALIZE'))");
            DB::statement("ALTER TABLE ai_requests ADD CONSTRAINT ai_requests_status_check CHECK (status IN ('PENDING','COMPLETED','FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};

