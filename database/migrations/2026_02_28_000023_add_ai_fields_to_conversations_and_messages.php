<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (!Schema::hasColumn('conversations', 'appraisal_snapshot_normalized')) {
                $table->json('appraisal_snapshot_normalized')->nullable()->after('appraisal_snapshot');
            }

            if (!Schema::hasColumn('conversations', 'normalization_meta')) {
                $table->json('normalization_meta')->nullable()->after('appraisal_snapshot_normalized');
            }

            if (!Schema::hasColumn('conversations', 'last_ai_error_code')) {
                $table->string('last_ai_error_code')->nullable()->after('normalization_meta');
            }
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            if (!Schema::hasColumn('conversation_messages', 'turn_id')) {
                $table->uuid('turn_id')->nullable()->after('event_id');
                $table->index(['conversation_id', 'turn_id']);
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS conversation_messages_one_assistant_per_turn
                ON conversation_messages (conversation_id, turn_id, role)
                WHERE role = 'assistant' AND turn_id IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS conversation_messages_one_assistant_per_turn');
        }

        Schema::table('conversation_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('conversation_messages', 'turn_id')) {
                $table->dropIndex(['conversation_id', 'turn_id']);
                $table->dropColumn('turn_id');
            }
        });

        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'last_ai_error_code')) {
                $table->dropColumn('last_ai_error_code');
            }
            if (Schema::hasColumn('conversations', 'normalization_meta')) {
                $table->dropColumn('normalization_meta');
            }
            if (Schema::hasColumn('conversations', 'appraisal_snapshot_normalized')) {
                $table->dropColumn('appraisal_snapshot_normalized');
            }
        });
    }
};

