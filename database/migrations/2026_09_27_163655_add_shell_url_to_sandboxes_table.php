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
            $table->string('shell_url')->nullable()->after('preview_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sandboxes', function (Blueprint $table) {
            $table->dropColumn('shell_url');
        });
    }
};
