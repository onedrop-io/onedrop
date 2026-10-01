<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which organization's project each run was for (ORG-005), so its usage still counts after the project is deleted.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_usages', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['organization_id', 'created_at']);
        });

        DB::table('agent_usages')->whereNotNull('project_id')->update([
            'organization_id' => DB::table('projects')->select('organization_id')->whereColumn('projects.id', 'agent_usages.project_id'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_usages', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
