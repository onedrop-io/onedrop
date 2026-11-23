<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vercel as a hosting provider (HOST-011): where each project would rather be hosted, and where each deploy went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // "vercel" or "fly-cloudflare"; null uses the organization's default.
            $table->string('hosting_platform')->nullable();
        });

        Schema::table('deployments', function (Blueprint $table) {
            // fly, cloudflare or vercel.
            $table->string('host')->nullable();
            // The deployment at the provider (Vercel's id), to put it back (HOST-005).
            $table->string('external_id')->nullable();
        });

        DB::table('deployments')->where('kind', 'static')->update(['host' => 'cloudflare']);
        DB::table('deployments')->where('kind', 'server')->update(['host' => 'fly']);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('hosting_platform');
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['host', 'external_id']);
        });
    }
};
