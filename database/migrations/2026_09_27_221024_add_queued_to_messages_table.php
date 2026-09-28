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
        Schema::table('messages', function (Blueprint $table) {
            // Sent while the agent was busy; runs when the current run finishes.
            $table->boolean('queued')->default(false)->after('content');
            $table->index(['project_id', 'queued']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'queued']);
            $table->dropColumn('queued');
        });
    }
};
