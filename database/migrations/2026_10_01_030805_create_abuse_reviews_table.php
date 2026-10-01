<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hosted install's abuse checks of publicly published and shared projects (PUB-003), and the platform
 * admins' decisions on the ones held for review (ADMIN-006). One row per project.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('abuse_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('trigger');
            $table->float('score')->nullable();
            $table->json('reasons')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'flagged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abuse_reviews');
    }
};
