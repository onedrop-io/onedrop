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
            // Goes up each time the sandbox's file watcher sees files added, removed or renamed (FILE-004).
            $table->unsignedInteger('files_version')->default(0)->after('ssh_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sandboxes', function (Blueprint $table) {
            $table->dropColumn('files_version');
        });
    }
};
