<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pull request's task remembers when its pull request was merged or closed, so it's moved to Done once (GIT-023);
     * a task opening a pull request keeps the title, description and link written for it (GIT-024).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('pull_request_state')->nullable();
            $table->json('pull_request_draft')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['pull_request_state', 'pull_request_draft']);
        });
    }
};
