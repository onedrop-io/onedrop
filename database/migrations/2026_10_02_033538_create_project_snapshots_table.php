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
        Schema::create('project_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // after a turn, before an update, daily
            $table->string('reason');
            // layer => {path, fingerprint, compression, size}
            $table->json('layers');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_snapshots');
    }
};
