<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pull request previews (GIT-026..028): whether a project previews every pull request by itself, and per pull
     * request's task its link's key, its GitHub Deployment and sticky comment, and why GitHub last refused them.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Off for every project, existing and new, until its owner turns it on.
            $table->boolean('preview_pull_requests')->default(false);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('preview_key', 64)->nullable();
            $table->unsignedBigInteger('preview_deployment_id')->nullable();
            $table->string('preview_deployment_sha', 40)->nullable();
            $table->unsignedBigInteger('preview_comment_id')->nullable();
            $table->string('preview_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['preview_key', 'preview_deployment_id', 'preview_deployment_sha', 'preview_comment_id', 'preview_error']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('preview_pull_requests');
        });
    }
};
