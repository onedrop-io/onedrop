<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // The target's own address for the published app; published_url is the primary custom domain when it has one (DOM-002).
            $table->string('published_default_url')->nullable();
        });

        DB::table('projects')->whereNotNull('published_url')->update(['published_default_url' => DB::raw('published_url')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('published_default_url');
        });
    }
};
