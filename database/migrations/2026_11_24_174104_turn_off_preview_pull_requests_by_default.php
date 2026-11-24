<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Previewing every pull request (GIT-026) starts off for every project, existing and new, until its owner turns it
     * on. The first release added the column on for every project, which would have checked out all their open pull
     * requests within the hour.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('preview_pull_requests')->default(false)->change();
        });

        DB::table('projects')->update(['preview_pull_requests' => false]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('preview_pull_requests')->default(true)->change();
        });
    }
};
