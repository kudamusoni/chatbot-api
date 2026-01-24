<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id'); // Matches users.id (BIGINT)
            $table->uuid('client_id');
            $table->string('role')->default('member');
            $table->timestamps();

            $table->primary(['user_id', 'client_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_user');
    }
};
