<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Addressing pull request review comments (GIT-031): the project's choice of whose comments its pull requests'
     * tasks address by themselves and whether replies post without asking, each task's switch, its rounds in a row and
     * the threads handed to its agent, and the replies the agent drafts.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('address_review_comments')->default('off')->after('preview_pull_requests_since');
            $table->boolean('post_review_replies')->default(false)->after('address_review_comments');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('pull_request_address_reviews')->default(false)->after('pull_request_fix_attempts');
            $table->unsignedInteger('pull_request_review_rounds')->default(0)->after('pull_request_address_reviews');
            $table->json('pull_request_review_batch')->nullable()->after('pull_request_review_rounds');
            $table->unsignedInteger('pull_request_open_threads')->nullable()->after('pull_request_review_batch');
        });

        Schema::create('review_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('thread_id');
            $table->string('path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('outcome');
            $table->text('body');
            $table->string('status')->default('draft');
            $table->string('commit_sha')->nullable();
            $table->unsignedBigInteger('github_comment_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_replies');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['pull_request_address_reviews', 'pull_request_review_rounds', 'pull_request_review_batch', 'pull_request_open_threads']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['address_review_comments', 'post_review_replies']);
        });
    }
};
