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
        Schema::table('project_snapshots', function (Blueprint $table) {
            // Suspend the sandbox once the snapshot is taken: it was taken because the project went unused (SBX-009).
            $table->boolean('suspend_after')->default(false)->after('external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_snapshots', function (Blueprint $table) {
            $table->dropColumn('suspend_after');
        });
    }
};
