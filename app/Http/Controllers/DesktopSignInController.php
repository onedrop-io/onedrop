<?php

namespace App\Http\Controllers;

use App\Actions\Auth\DesktopSignIn;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DesktopSignInController extends Controller
{
    /**
     * The desktop app opened this page in the browser (DESK-001): ask the signed-in user to let it in.
     */
    public function show(Request $request, DesktopSignIn $signIn): Response|HttpResponse
    {
        if ($refusal = $this->refusal($request, $signIn)) {
            return $refusal;
        }

        return Inertia::render('auth/desktop-authorize', [
            'device' => $this->device($request),
            'email' => $request->user()->email,
            'request' => $request->only('redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'device'),
        ]);
    }

    /**
     * Allowed or cancelled: send the browser back to the app, with a one-time code when allowed.
     */
    public function store(Request $request, DesktopSignIn $signIn): SymfonyResponse
    {
        if ($refusal = $this->refusal($request, $signIn)) {
            return $refusal;
        }

        $redirectUri = (string) $request->input('redirect_uri');
        $query = $request->boolean('allow')
            ? ['code' => $signIn->issueCode($request->user(), $redirectUri, (string) $request->input('code_challenge'), $this->device($request))]
            : ['error' => 'access_denied'];

        // A full-page visit, not Inertia's request: the browser leaves for the app's own address.
        return Inertia::location($redirectUri.'?'.http_build_query([...$query, 'state' => (string) $request->input('state')]));
    }

    /**
     * Explain, rather than redirect, when the request isn't from the desktop app on this computer.
     */
    protected function refusal(Request $request, DesktopSignIn $signIn): ?HttpResponse
    {
        if ($signIn->isLoopback((string) $request->input('redirect_uri'))
            && $signIn->isChallenge($request->input('code_challenge'), $request->input('code_challenge_method'))
            && is_string($request->input('state')) && strlen($request->input('state')) <= 200) {
            return null;
        }

        return response()->view('onedrop.refused', [
            'title' => __('This sign-in link doesn\'t work'),
            'message' => __('Start signing in from the OneDrop desktop app again.'),
        ], 400);
    }

    /**
     * What the app calls this computer, e.g. "Jeff's MacBook Pro".
     */
    protected function device(Request $request): string
    {
        return Str::limit(Str::squish((string) $request->input('device')), 80, '') ?: __('Desktop app');
    }
}
