<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a task's copy of the app starts (TASK-005): a fresh clone of a branch on the remote, Main, or another task's
     * copy. Tasks from before start from Main, as they did. `branch` is the branch its copy works on, so a second task
     * picking the same one gets its own branch off it.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('start_from')->nullable();
            $table->string('start_branch')->nullable();
            $table->foreignId('start_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('branch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('start_task_id');
            $table->dropColumn(['start_from', 'start_branch', 'branch']);
        });
    }
};
