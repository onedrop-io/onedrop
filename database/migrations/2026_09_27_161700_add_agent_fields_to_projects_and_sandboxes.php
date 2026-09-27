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
            $table->string('agent_session_id')->nullable()->after('status');
        });

        Schema::table('sandboxes', function (Blueprint $table) {
            $table->string('events_token_hash', 64)->nullable()->after('error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('agent_session_id');
        });

        Schema::table('sandboxes', function (Blueprint $table) {
            $table->dropColumn('events_token_hash');
        });
    }
};
