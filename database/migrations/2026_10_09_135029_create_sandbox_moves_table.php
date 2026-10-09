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
        Schema::create('sandbox_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The project's main sandbox, or a task's copy.
            $table->foreignId('sandbox_id')->constrained()->cascadeOnDelete();
            // provider, update, computer, recreate, restore
            $table->string('reason');
            $table->boolean('keep_files')->default(true);
            $table->string('phase');
            $table->string('from_provider')->nullable();
            $table->string('from_external_id')->nullable()->index();
            // Null until asked.
            $table->boolean('from_answered')->nullable();
            $table->string('to_provider')->nullable();
            $table->string('to_external_id')->nullable()->index();
            $table->string('source')->nullable();
            $table->foreignId('snapshot_id')->nullable()->constrained('project_snapshots')->nullOnDelete();
            // Background work in a sandbox the next step checks on.
            $table->json('work')->nullable();
            // What else the move does when it's done, e.g. the computer to go back to if it fails.
            $table->json('options')->nullable();
            $table->string('old_status')->nullable();
            $table->foreignId('recovered_snapshot_id')->nullable()->constrained('project_snapshots')->nullOnDelete();
            $table->text('error')->nullable();
            $table->json('messages')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['sandbox_id', 'phase']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sandbox_moves');
    }
};
