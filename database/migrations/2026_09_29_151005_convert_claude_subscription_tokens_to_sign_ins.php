<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pasted Claude subscription tokens become sign-ins done in the sandbox (AI-005): Anthropic forbids
     * apps from storing Claude tokens, so they're deleted, not kept.
     */
    public function up(): void
    {
        DB::table('agent_connections')->where('credential_type', 'oauth_token')->update([
            'credential_type' => 'claude_login',
            'credential' => Crypt::encryptString(''),
            'hint' => '',
            'verified_at' => null,
        ]);
    }

    /**
     * The deleted tokens can't come back; the sign-ins stay.
     */
    public function down(): void {}
};
