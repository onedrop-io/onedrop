<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each task's own agent, model and reasoning level (TASK-007). Existing tasks keep what their project uses now.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('agent_harness')->nullable()->after('agent_session_id');
            $table->string('agent_provider')->nullable()->after('agent_harness');
            $table->string('agent_model')->nullable()->after('agent_provider');
            $table->string('agent_variant')->nullable()->after('agent_model');
            $table->boolean('agent_auto')->default(false)->after('agent_variant');
        });

        $from = fn (string $column) => DB::table('projects')->select($column)->whereColumn('projects.id', 'tasks.project_id');

        DB::table('tasks')->update([
            'agent_harness' => $from('agent_harness'),
            'agent_provider' => $from('agent_provider'),
            'agent_model' => $from('agent_model'),
            'agent_variant' => $from('agent_variant'),
            'agent_auto' => $from('agent_auto'),
        ]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['agent_harness', 'agent_provider', 'agent_model', 'agent_variant', 'agent_auto']);
        });
    }
};
