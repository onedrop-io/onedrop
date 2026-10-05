<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The computer a project runs on (DESK-010), the hosts on the user's network it may reach (DESK-009), and the
     * SSH key a desktop app made for its computer (DESK-008), which goes when that computer is signed out.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->json('network_hosts')->nullable();
        });

        Schema::table('ssh_keys', function (Blueprint $table) {
            $table->foreignId('desktop_token_id')->nullable()->constrained('personal_access_tokens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ssh_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desktop_token_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn('network_hosts');
        });
    }
};
