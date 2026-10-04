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
        Schema::create('project_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // One project per hostname on the install (DOM-001).
            $table->string('hostname')->unique();
            $table->boolean('primary')->default(false);
            // pending or active
            $table->string('status')->default('pending');
            // How it's connected now: caddy, cloudflare-saas (the gateway Worker's zone), fly or cloudflare-worker; null when it isn't.
            $table->string('via')->nullable();
            // The provider's id for it (a Cloudflare custom hostname or Workers domain).
            $table->string('external_id')->nullable();
            // The DNS records to add for where it's connected: [{type, name, value}].
            $table->json('records')->nullable();
            // What's wrong while it's waiting, e.g. where its DNS points now.
            $table->text('error')->nullable();
            // When checking started (adding it, connecting it somewhere new, Check now): checks slow down after 10 minutes and stop after two days.
            $table->timestamp('checking_since')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_domains');
    }
};
