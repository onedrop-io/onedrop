<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the agent commits each turn to the branch (SCM-003). Projects already connected to a repository stop:
     * their changes are left for the user to commit.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('commit_turns')->default(true);
        });

        DB::table('projects')->whereNotNull('git_remote_url')->update(['commit_turns' => false]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('commit_turns');
        });
    }
};
