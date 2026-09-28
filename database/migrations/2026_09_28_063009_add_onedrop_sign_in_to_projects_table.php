<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Sign in with OneDrop" for the project's app: its OAuth client and who may sign in.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('onedrop_enabled')->default(false);
            $table->string('onedrop_client_id')->nullable()->unique();
            $table->string('onedrop_client_secret')->nullable();
            $table->string('onedrop_callback_path')->nullable();
            // Null: everyone with a OneDrop account; otherwise only members of these groups.
            $table->json('onedrop_group_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['onedrop_client_id']);
            $table->dropColumn(['onedrop_enabled', 'onedrop_client_id', 'onedrop_client_secret', 'onedrop_callback_path', 'onedrop_group_ids']);
        });
    }
};
