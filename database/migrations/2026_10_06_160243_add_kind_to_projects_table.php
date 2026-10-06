<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An app someone builds, or a person's own computer (CMP-001), which is a project nobody sees in project lists.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('kind')->default('app')->index();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
