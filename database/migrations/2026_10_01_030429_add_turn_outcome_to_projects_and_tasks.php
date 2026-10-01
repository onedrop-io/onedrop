<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the main chat's and each task's last agent turn ended (PRJ-011), cleared when the next one starts.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('turn_outcome')->nullable();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('turn_outcome')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('turn_outcome');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('turn_outcome');
        });
    }
};
