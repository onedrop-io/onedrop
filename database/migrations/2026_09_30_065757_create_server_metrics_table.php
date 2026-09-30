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
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at')->index();
            // CPU time since boot (busy and total), so each sample's use comes from the one before it.
            $table->unsignedBigInteger('cpu_busy')->nullable();
            $table->unsignedBigInteger('cpu_total')->nullable();
            $table->unsignedBigInteger('memory_used')->nullable();
            $table->unsignedBigInteger('memory_total')->nullable();
            $table->unsignedBigInteger('disk_used')->nullable();
            $table->unsignedBigInteger('disk_total')->nullable();
            // Bytes since boot.
            $table->unsignedBigInteger('disk_read')->nullable();
            $table->unsignedBigInteger('disk_written')->nullable();
            $table->unsignedBigInteger('network_in')->nullable();
            $table->unsignedBigInteger('network_out')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
