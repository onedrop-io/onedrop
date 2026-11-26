<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One provider account (a GitHub account, say) can be connected to several logins (AUTH-003); each login still
     * has at most one connection per provider.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->index(['provider', 'provider_id']);
            $table->dropUnique(['provider', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unique(['provider', 'provider_id']);
            $table->dropIndex(['provider', 'provider_id']);
        });
    }
};
