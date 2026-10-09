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
        Schema::table('project_snapshots', function (Blueprint $table) {
            // pending while the sandbox packs and uploads it in the background, then ready (or failed)
            $table->string('status')->default('ready')->after('reason');
            // A task's copy, carried to a new sandbox by a move (SBX-005); null for the project's own snapshots.
            $table->foreignId('task_id')->nullable()->after('project_id')->constrained()->cascadeOnDelete();
            // The sandbox a pending snapshot is being taken in.
            $table->string('external_id')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
            $table->dropColumn(['status', 'external_id']);
        });
    }
};
