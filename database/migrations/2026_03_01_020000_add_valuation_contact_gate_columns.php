<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->uuid('lead_id')->nullable()->after('client_id');
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->index('lead_id');
            $table->index(['client_id', 'lead_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->uuid('lead_capture_action_id')->nullable()->after('request_event_id');
            $table->unique(
                ['client_id', 'conversation_id', 'lead_capture_action_id'],
                'leads_capture_action_unique'
            );
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->uuid('valuation_contact_lead_id')->nullable()->after('lead_identity_candidate');
            $table->foreign('valuation_contact_lead_id')
                ->references('id')
                ->on('leads')
                ->nullOnDelete();
            $table->index('valuation_contact_lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['valuation_contact_lead_id']);
            $table->dropIndex(['valuation_contact_lead_id']);
            $table->dropColumn('valuation_contact_lead_id');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique('leads_capture_action_unique');
            $table->dropColumn('lead_capture_action_id');
        });

        Schema::table('valuations', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropIndex(['lead_id']);
            $table->dropIndex(['client_id', 'lead_id']);
            $table->dropColumn('lead_id');
        });
    }
};

