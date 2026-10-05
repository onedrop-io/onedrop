<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSshKeys;
use App\Models\SshKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopSshKeyController extends Controller
{
    /**
     * The SSH key the desktop app made for its computer (DESK-008): one per sign-in, named after the computer,
     * replaced when the app makes a new one, and gone when the computer is signed out.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'public_key' => ['required', 'string', 'max:16000', function (string $attribute, string $value, Closure $fail) {
                if (SshKey::parse($value) === null) {
                    $fail(__("That isn't an SSH public key."));
                }
            }],
        ]);

        $user = $request->user();
        $token = $user->currentAccessToken();
        $parsed = SshKey::parse($validated['public_key']);

        // The same key added by hand already lets this computer in.
        $existing = $user->sshKeys()->where('fingerprint', $parsed['fingerprint'])->first();

        if ($existing === null || $existing->desktop_token_id === $token->getKey()) {
            $user->sshKeys()->updateOrCreate(['desktop_token_id' => $token->getKey()], [
                'name' => mb_substr(__('OneDrop desktop (:computer)', ['computer' => $token->name]), 0, 100),
                'public_key' => "{$parsed['type']} {$parsed['key']}",
                'fingerprint' => $parsed['fingerprint'],
            ]);

            SyncSshKeys::dispatch($user);
        }

        return response()->json(['fingerprint' => $parsed['fingerprint']]);
    }
}
