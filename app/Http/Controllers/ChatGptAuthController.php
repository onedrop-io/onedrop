<?php

namespace App\Http\Controllers;

use App\Actions\ConnectAgent;
use App\Sandbox\Agents\ChatGptAuth;
use App\Sandbox\Agents\ChatGptSignInFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Sign in with ChatGPT" for Codex: the page shows a one-time code, the user approves it
 * on OpenAI's site, and the page polls here until the sign-in lands.
 */
class ChatGptAuthController extends Controller
{
    /** OpenAI's codes expire after 15 minutes. */
    protected const CODE_LIFETIME_MINUTES = 15;

    /**
     * Start a sign-in and return the code to show.
     */
    public function store(Request $request, ChatGptAuth $auth): JsonResponse
    {
        try {
            $device = $auth->requestDeviceCode();
        } catch (ChatGptSignInFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->put('chatgpt.device', [
            'device_auth_id' => $device['device_auth_id'],
            'user_code' => $device['user_code'],
            'expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES)->getTimestamp(),
        ]);

        return response()->json([
            'user_code' => $device['user_code'],
            'verification_url' => $device['verification_url'],
            'interval' => $device['interval'],
        ]);
    }

    /**
     * Check whether the user has approved the code yet, and connect Codex once they have.
     */
    public function poll(Request $request, ChatGptAuth $auth, ConnectAgent $connect): JsonResponse
    {
        $device = $request->session()->get('chatgpt.device');

        if (! $device || $device['expires_at'] < now()->getTimestamp()) {
            $request->session()->forget('chatgpt.device');

            return response()->json(['message' => __('The ChatGPT sign-in code expired. Start again.')], 422);
        }

        try {
            $tokens = $auth->poll($device['device_auth_id'], $device['user_code']);
        } catch (ChatGptSignInFailed $e) {
            $request->session()->forget('chatgpt.device');

            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($tokens === null) {
            return response()->json(['status' => 'pending']);
        }

        $request->session()->forget('chatgpt.device');
        $connect->chatGpt($request->user(), $tokens);

        // Shown on the page the browser visits next.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ChatGPT connected.')]);

        return response()->json(['status' => 'connected']);
    }
}
