<?php

namespace App\Http\Controllers;

use App\Actions\ConnectAgent;
use App\Enums\AgentProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpenRouter's OAuth PKCE flow: the user signs in at OpenRouter and we get
 * back a key they own and can revoke. https://openrouter.ai/docs/use-cases/oauth-pkce
 */
class OpenRouterAuthController extends Controller
{
    /**
     * Send the user to OpenRouter to authorize a key.
     */
    public function redirect(Request $request): Response
    {
        $verifier = Str::random(64);

        $request->session()->put('openrouter.verifier', $verifier);
        $request->session()->put('openrouter.return_to', url()->previous(route('onboarding.ai')));

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return Inertia::location('https://openrouter.ai/auth?'.http_build_query([
            'callback_url' => route('openrouter.callback'),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));
    }

    /**
     * Exchange the returned code for a key and store it.
     */
    public function callback(Request $request, ConnectAgent $connect): RedirectResponse
    {
        $verifier = $request->session()->pull('openrouter.verifier');
        $returnTo = $request->session()->pull('openrouter.return_to', route('onboarding.ai'));

        if (! $verifier || ! $request->filled('code')) {
            return $this->failed($returnTo, __('OpenRouter sign-in was cancelled or expired. Try again.'));
        }

        try {
            $response = Http::post('https://openrouter.ai/api/v1/auth/keys', [
                'code' => $request->string('code')->toString(),
                'code_verifier' => $verifier,
                'code_challenge_method' => 'S256',
            ]);
        } catch (ConnectionException) {
            return $this->failed($returnTo, __("Couldn't reach OpenRouter. Try again."));
        }

        if ($response->failed() || ! $response->json('key')) {
            return $this->failed($returnTo, __('OpenRouter sign-in failed. Try again.'));
        }

        try {
            $connect->handle($request->user(), AgentProvider::OpenRouter, $response->json('key'));
        } catch (ValidationException $e) {
            return $this->failed($returnTo, $e->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('OpenRouter connected.')]);

        return $returnTo === route('onboarding.ai') ? to_route('dashboard') : redirect($returnTo);
    }

    /**
     * Return to where the user started with an error toast.
     */
    protected function failed(string $returnTo, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect($returnTo);
    }
}
