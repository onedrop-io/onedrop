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
        Schema::table('projects', function (Blueprint $table) {
            // What the sandbox has that the hosted app doesn't yet: {count, commits: [{sha, message}]} (HOST-004).
            $table->json('hosting_changes')->nullable();
            // Update the hosted app by itself after a turn that went well (HOST-006).
            $table->boolean('auto_deploy')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['hosting_changes', 'auto_deploy']);
        });
    }
};
