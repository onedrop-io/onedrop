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
        Schema::create('hosted_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // app, volume, postgres, redis, bucket, site
            $table->string('kind');
            // fly, neon, upstash, cloudflare
            $table->string('provider');
            // Whose account it's in: organization (its own, HOST-003) or platform (the install's).
            $table->string('owner');
            $table->string('name');
            // The provider's id for it.
            $table->string('external_id')->nullable();
            $table->string('region')->nullable();
            // Connection details (addresses, keys scoped to it), encrypted.
            $table->text('details')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hosted_services');
    }
};
