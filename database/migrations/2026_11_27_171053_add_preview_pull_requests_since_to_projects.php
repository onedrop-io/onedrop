<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Previewing only pull requests opened from a moment on (GIT-026): when set, older ones aren't checked out by themselves.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('preview_pull_requests_since')->nullable()->after('preview_pull_requests');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('preview_pull_requests_since');
        });
    }
};
