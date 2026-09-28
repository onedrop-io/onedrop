<?php

namespace App\Http\Controllers;

use App\Sandbox\OneDropSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Sign in with OneDrop" for apps built here: OAuth 2.0 authorization code (with optional PKCE)
 * and a user-info endpoint, so any stack's auth library can use it.
 */
class OneDropOAuthController extends Controller
{
    /**
     * The browser arrives from the app (the user is signed in to OneDrop by the auth middleware),
     * and goes back to the app's callback with a one-time code.
     */
    public function authorize(Request $request, OneDropSignIn $oneDrop): RedirectResponse|Response
    {
        $project = $oneDrop->client($request->query('client_id'));
        $redirectUri = (string) $request->query('redirect_uri');

        // Never redirect to an address we can't vouch for: explain here instead.
        if (! $project || ! in_array($redirectUri, $oneDrop->redirectUris($project), true)) {
            return $this->explain(__("This app can't use Sign in with OneDrop"), __('Its sign-in isn\'t set up for this address. Ask the person who builds it to check Tools → Users & Auth.'), 400);
        }

        $back = fn (array $query) => redirect()->away($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query(array_filter([
            ...$query,
            'state' => $request->query('state'),
        ], fn ($value) => $value !== null)));

        if ($request->query('response_type') !== 'code') {
            return $back(['error' => 'unsupported_response_type']);
        }

        $challenge = $request->query('code_challenge');

        if ($challenge !== null && ($request->query('code_challenge_method', 'plain') !== 'S256' || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', (string) $challenge))) {
            return $back(['error' => 'invalid_request', 'error_description' => 'Only S256 PKCE challenges are supported.']);
        }

        if (! $oneDrop->allows($project, $request->user())) {
            return $this->explain(__('You can\'t sign in to :app', ['app' => $project->name]), __('It only lets in members of certain groups on OneDrop. Ask its owner, :owner, to add you.', ['owner' => $project->user->name]), 403);
        }

        return $back(['code' => $oneDrop->issueCode($project, $request->user(), [
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
        ])]);
    }

    /**
     * The app's server trades the code for an access token.
     */
    public function token(Request $request, OneDropSignIn $oneDrop): JsonResponse
    {
        // client_secret_basic or client_secret_post.
        $clientId = $request->getUser() ?? $request->input('client_id');
        $secret = $request->getPassword() ?? $request->input('client_secret');
        $project = $oneDrop->authenticate($clientId !== null ? urldecode((string) $clientId) : null, $secret !== null ? urldecode((string) $secret) : null);

        if (! $project) {
            return $this->oauthError('invalid_client', 401);
        }

        if ($request->input('grant_type') !== 'authorization_code' || ! is_string($request->input('code'))) {
            return $this->oauthError('unsupported_grant_type');
        }

        $token = $oneDrop->exchange($project, $request->input('code'), $request->input('redirect_uri'), $request->input('code_verifier'));

        if ($token === null) {
            return $this->oauthError('invalid_grant');
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => OneDropSignIn::TOKEN_SECONDS,
            'scope' => 'openid profile email',
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Who the access token belongs to.
     */
    public function userinfo(Request $request, OneDropSignIn $oneDrop): JsonResponse
    {
        $user = $request->bearerToken() ? $oneDrop->userForToken($request->bearerToken()) : null;

        if (! $user) {
            return response()->json(['error' => 'invalid_token'], 401)->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        return response()->json($oneDrop->claims($user))->header('Cache-Control', 'no-store');
    }

    protected function oauthError(string $error, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error], $status)->header('Cache-Control', 'no-store');
    }

    protected function explain(string $title, string $message, int $status): Response
    {
        return response()->view('onedrop.refused', ['title' => $title, 'message' => $message], $status);
    }
}
