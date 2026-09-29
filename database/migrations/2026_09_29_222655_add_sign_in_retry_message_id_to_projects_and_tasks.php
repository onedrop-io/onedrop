<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The message whose Claude Code run failed because Claude wasn't signed in, run again once it is (AI-005).
     */
    public function up(): void
    {
        foreach (['projects', 'tasks'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('sign_in_retry_message_id')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['projects', 'tasks'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('sign_in_retry_message_id');
            });
        }
    }
};
