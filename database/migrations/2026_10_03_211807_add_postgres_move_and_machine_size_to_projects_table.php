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
            // The hosted SQLite file a Move to Postgres copies into the app's new Postgres, until it has (HOST-009).
            $table->string('hosting_sqlite_import')->nullable();
            // The hosted app's machine size; null is the install's default (HOST-010).
            $table->string('hosting_size')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['hosting_sqlite_import', 'hosting_size']);
        });
    }
};
