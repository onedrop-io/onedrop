<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a project is published: its server's own domain or Tailscale (null: never published, or Tailscale before
     * there was a choice).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('publish_target')->nullable()->after('publish_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('publish_target');
        });
    }
};
