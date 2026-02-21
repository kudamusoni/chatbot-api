<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('email_hash', 64)->nullable()->after('email');
            $table->string('phone_hash', 64)->nullable()->after('phone_normalized');
            $table->text('notes')->nullable()->after('status');
            $table->unsignedBigInteger('updated_by')->nullable()->after('notes');

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['client_id', 'email_hash']);
            $table->index(['client_id', 'phone_hash']);
            $table->index(['client_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropIndex(['client_id', 'email_hash']);
            $table->dropIndex(['client_id', 'phone_hash']);
            $table->dropIndex(['client_id', 'status', 'created_at']);
            $table->dropColumn(['email_hash', 'phone_hash', 'notes', 'updated_by']);
        });
    }
};
