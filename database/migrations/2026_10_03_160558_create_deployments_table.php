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
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The project's first deployment is 1.
            $table->unsignedInteger('number');
            // server (a Fly machine) or static (Cloudflare static assets); known once the sandbox is read
            $table->string('kind')->nullable();
            // running, live, failed
            $table->string('status');
            // Where a running deployment is: inspect, package, provision, build, release, verify, upload
            $table->string('step');
            // What steps hand to each other (the host manifest, uploaded files, the builder's machine)
            $table->json('state')->nullable();
            $table->unsignedInteger('polls')->default(0);
            // The image it shipped, for rolling back to the last good one.
            $table->string('image')->nullable();
            $table->string('url')->nullable();
            $table->text('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
