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
            $table->string('publish_status')->nullable()->after('agent_session_id');
            $table->string('publish_visibility')->nullable()->after('publish_status');
            $table->string('published_url')->nullable()->after('publish_visibility');
            $table->timestamp('published_at')->nullable()->after('published_url');
            $table->foreignId('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->text('publish_error')->nullable()->after('published_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn(['publish_status', 'publish_visibility', 'published_url', 'published_at', 'publish_error']);
        });
    }
};
