<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A task checked out from a GitHub pull request (GIT-014): which one, its branches, pushing after each turn,
     * and its checks as last seen, with automatic fixing (GIT-015).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('pull_request_number')->nullable();
            $table->string('pull_request_branch')->nullable();
            $table->string('pull_request_base')->nullable();
            $table->boolean('pull_request_fork')->default(false);
            $table->boolean('pull_request_push')->default(true);
            $table->boolean('pull_request_autofix')->default(false);
            $table->unsignedTinyInteger('pull_request_fix_attempts')->default(0);
            $table->string('pull_request_head_sha', 40)->nullable();
            $table->string('pull_request_checks')->nullable();

            $table->index(['project_id', 'pull_request_number']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'pull_request_number']);
            $table->dropColumn(['pull_request_number', 'pull_request_branch', 'pull_request_base', 'pull_request_fork', 'pull_request_push', 'pull_request_autofix', 'pull_request_fix_attempts', 'pull_request_head_sha', 'pull_request_checks']);
        });
    }
};
