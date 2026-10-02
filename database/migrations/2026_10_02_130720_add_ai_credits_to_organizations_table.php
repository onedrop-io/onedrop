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
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('ai_credits_key')->nullable();
            $table->string('ai_credits_key_hash')->nullable();
            $table->decimal('ai_credits_charged', 12, 6)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['ai_credits_key', 'ai_credits_key_hash', 'ai_credits_charged']);
        });
    }
};
