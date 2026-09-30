<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a sandbox was last used, when it was suspended for sitting idle, and when it was then stopped (SBX-007).
     */
    public function up(): void
    {
        Schema::table('sandboxes', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sandboxes', function (Blueprint $table) {
            $table->dropColumn(['last_active_at', 'suspended_at', 'stopped_at']);
        });
    }
};
