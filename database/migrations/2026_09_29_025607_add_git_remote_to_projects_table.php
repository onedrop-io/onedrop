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
        Schema::table('projects', function (Blueprint $table) {
            $table->string('git_remote_url')->nullable();
            $table->string('git_remote_username')->nullable();
            $table->text('git_remote_token')->nullable();
            $table->string('git_sync_status')->nullable();
            $table->text('git_sync_error')->nullable();
            $table->timestamp('git_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['git_remote_url', 'git_remote_username', 'git_remote_token', 'git_sync_status', 'git_sync_error', 'git_synced_at']);
        });
    }
};
