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
        Schema::create('table_fields', function (Blueprint $table) {
            $table->id();
            $table->string('table')->index();
            $table->string('name');
            $table->string('type');
            $table->text('description')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('table_views', function (Blueprint $table) {
            $table->id();
            $table->string('table')->index();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->json('config')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('public_token', 40)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('table_comments', function (Blueprint $table) {
            $table->id();
            $table->string('table');
            $table->unsignedBigInteger('record_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps(precision: 6);

            $table->index(['table', 'record_id']);
        });

        Schema::create('table_activities', function (Blueprint $table) {
            $table->id();
            $table->string('table');
            $table->unsignedBigInteger('record_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('field')->nullable();
            $table->string('field_name')->nullable();
            $table->text('from')->nullable();
            $table->text('to')->nullable();
            $table->string('via')->nullable();
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->index(['table', 'record_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_activities');
        Schema::dropIfExists('table_comments');
        Schema::dropIfExists('table_views');
        Schema::dropIfExists('table_fields');
    }
};
