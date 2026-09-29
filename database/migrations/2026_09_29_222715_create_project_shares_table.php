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
        Schema::create('project_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->text('prompt');
            $table->string('page_path')->default('/');
            $table->string('card_status')->nullable();
            $table->text('card_error')->nullable();
            $table->string('screenshot_file')->nullable();
            $table->string('card_file')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('remixes')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_shares');
    }
};
