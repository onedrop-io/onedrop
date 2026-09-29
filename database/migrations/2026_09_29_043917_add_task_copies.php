<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A task can have its own copy of the project's sandbox (TASK-003); the project's main sandbox has no task.
     */
    public function up(): void
    {
        // A project now has its main sandbox plus one per task copy; each task has at most one.
        Schema::table('sandboxes', function (Blueprint $table) {
            $table->index('project_id');
            $table->dropUnique(['project_id']);
            $table->foreignId('task_id')->nullable()->unique()->after('project_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('base_commit')->nullable()->after('events_token_hash');
            $table->string('sync_status')->nullable()->after('base_commit');
            $table->text('sync_error')->nullable()->after('sync_status');
            $table->timestamp('applied_at')->nullable()->after('sync_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['base_commit', 'sync_status', 'sync_error', 'applied_at']);
        });

        Schema::table('sandboxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
            $table->unique('project_id');
            $table->dropIndex(['project_id']);
        });
    }
};
