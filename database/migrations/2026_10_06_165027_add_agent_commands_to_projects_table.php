<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The slash commands the project's agent reported on its last run, by agent (AGT-016).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('agent_commands')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('agent_commands');
        });
    }
};
