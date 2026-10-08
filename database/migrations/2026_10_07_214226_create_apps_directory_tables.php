<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organization's Apps page (APPS-001..003): whether an app is listed or featured, its groups, and each person's pins.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('apps_listed')->default(true);
            $table->timestamp('apps_featured_at')->nullable();
        });

        Schema::create('group_project', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['group_id', 'project_id']);
        });

        Schema::create('app_pins', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'project_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_pins');
        Schema::dropIfExists('group_project');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['apps_listed', 'apps_featured_at']);
        });
    }
};
