<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an agent step did (AGT-017): its command and output, a diff, or the model's thinking. Kept apart from
     * `meta` because it's only read when the user expands the step.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('detail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('detail');
        });
    }
};
