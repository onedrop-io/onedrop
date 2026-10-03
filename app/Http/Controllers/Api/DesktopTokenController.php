<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\DesktopSignIn;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DesktopTokenController extends Controller
{
    /**
     * The desktop app trades the sign-in's one-time code and its PKCE verifier for an API token (DESK-001).
     */
    public function store(Request $request, DesktopSignIn $signIn): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:200'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
            'redirect_uri' => ['required', 'string', 'max:200'],
        ]);

        $token = $signIn->exchange($validated['code'], $validated['code_verifier'], $validated['redirect_uri']);

        if ($token === null) {
            return response()->json(['message' => __('That sign-in expired. Try again.')], 422);
        }

        return response()->json(['token' => $token->plainTextToken])->header('Cache-Control', 'no-store');
    }

    /**
     * Sign the desktop app out: its token stops working.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
