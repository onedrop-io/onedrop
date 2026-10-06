<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drive's files and folders (DRIVE-001): each in one place (a person's My Drive, the organization's, or a group's),
     * under a parent folder or at the top. Every change takes the next revision, which sandboxes follow (DRIVE-003).
     */
    public function up(): void
    {
        Schema::create('drive_revisions', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('drive_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('space');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('drive_items')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_folder')->default(false);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime_type')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('blob')->nullable();
            $table->unsignedBigInteger('revision')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('trashed_at')->nullable()->index();
            $table->foreignId('trashed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'space', 'user_id', 'group_id', 'parent_id', 'name'], 'drive_items_place_index');
            $table->index(['organization_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_items');
        Schema::dropIfExists('drive_revisions');
    }
};
