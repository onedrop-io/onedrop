<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Earlier runs are marked from how their owner connects that provider today (a sign-in is
     * a plan, an Ollama server their own); runs on providers they've since disconnected stay unknown.
     */
    public function up(): void
    {
        Schema::table('agent_usages', function (Blueprint $table) {
            $table->string('paid_by')->nullable();
        });

        $paidBy = ['claude_login' => 'plan', 'chatgpt' => 'plan', 'ollama_server' => 'own_server', 'api_key' => 'api_key'];

        foreach (DB::table('agent_connections')->get(['user_id', 'provider', 'credential_type']) as $connection) {
            DB::table('agent_usages')
                ->where('user_id', $connection->user_id)
                ->where('provider', $connection->provider)
                // OpenCode can't use a Claude sign-in, so only Claude Code's runs were on one.
                ->when($connection->credential_type === 'claude_login', fn ($usages) => $usages->where('harness', 'claude_code'))
                ->whereNull('paid_by')
                ->update(['paid_by' => $paidBy[$connection->credential_type] ?? null]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_usages', function (Blueprint $table) {
            $table->dropColumn('paid_by');
        });
    }
};
