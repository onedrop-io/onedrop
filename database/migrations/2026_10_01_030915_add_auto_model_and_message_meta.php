<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auto model and reasoning (AGT-011), and what the platform decided about each message when it was sent
     * (the model Auto picked, an earlier decision it changes).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('agent_auto')->default(false);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->json('meta')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('agent_auto');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
