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
            $table->string('agent_provider')->nullable()->after('agent_session_id');
            $table->string('agent_model')->nullable()->after('agent_provider');
            $table->string('agent_variant')->nullable()->after('agent_model');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('favorite_models')->nullable()->after('is_admin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['agent_provider', 'agent_model', 'agent_variant']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('favorite_models');
        });
    }
};
