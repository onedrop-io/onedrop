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
        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('installation_id');
            $table->string('account_login');
            $table->string('account_type');
            $table->string('account_avatar_url')->nullable();
            $table->string('repository_selection')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'installation_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('github_installation_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('github_installation_id');
        });

        Schema::dropIfExists('github_installations');
    }
};
