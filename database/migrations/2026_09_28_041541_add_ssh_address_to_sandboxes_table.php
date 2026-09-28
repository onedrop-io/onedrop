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
        Schema::table('sandboxes', function (Blueprint $table) {
            // "host:port" where the sandbox's SSH server is reachable, if it has one.
            $table->string('ssh_address')->nullable()->after('shell_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sandboxes', function (Blueprint $table) {
            $table->dropColumn('ssh_address');
        });
    }
};
