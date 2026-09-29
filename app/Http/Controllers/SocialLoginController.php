<?php

namespace App\Http\Controllers;

use App\Actions\Auth\ResolveSocialUser;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Log in, sign up, or connect an account with Google, Microsoft, GitHub,
 * GitLab, or an OIDC issuer. Signed-in users use the same flow to connect a
 * provider to their account.
 */
class SocialLoginController extends Controller
{
    /**
     * Send the person to the provider to sign in.
     */
    public function redirect(SocialProvider $provider): Response
    {
        abort_unless($provider->isConfigured(), 404);

        return $this->driver($provider)->redirect();
    }

    /**
     * Handle the provider sending the person back.
     */
    public function callback(Request $request, SocialProvider $provider, ResolveSocialUser $resolve): RedirectResponse
    {
        // A GitHub App whose Callback URL is this login one: its return from a Tools → Git connection goes on there.
        if ($provider === SocialProvider::GitHub && GitHubAppController::isReturning($request)) {
            return redirect()->to(route('github-app.callback').'?'.http_build_query($request->query()));
        }

        abort_unless($provider->isConfigured(), 404);

        $failedRoute = $request->user() ? 'security.edit' : 'login';

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->failed($failedRoute, __(':provider sign-in was cancelled. Try again.', ['provider' => $provider->label()]));
        }

        try {
            $identity = $this->driver($provider)->user();
        } catch (Throwable $e) {
            report($e);

            return $this->failed($failedRoute, __(':provider sign-in failed. Try again.', ['provider' => $provider->label()]));
        }

        if ($request->user()) {
            return $this->connect($request->user(), $provider, $identity, $resolve);
        }

        try {
            $user = $resolve->handle($provider, $identity);
        } catch (ValidationException $e) {
            return to_route('login')->withErrors($e->errors());
        }

        return $this->logIn($request, $user);
    }

    /**
     * Connect the provider to the signed-in user.
     */
    protected function connect(User $user, SocialProvider $provider, SocialiteUser $identity, ResolveSocialUser $resolve): RedirectResponse
    {
        $owner = SocialAccount::where('provider', $provider)
            ->where('provider_id', (string) $identity->getId())
            ->value('user_id');

        if ($owner !== null && (int) $owner !== $user->id) {
            return $this->failed('security.edit', __('That :provider account is already connected to another user.', ['provider' => $provider->label()]));
        }

        $resolve->link($user, $provider, $identity);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider connected.', ['provider' => $provider->label()])]);

        return to_route('security.edit');
    }

    /**
     * Log the user in, asking for their two-factor code first if they use one.
     */
    protected function logIn(Request $request, User $user): RedirectResponse
    {
        if (Features::enabled(Features::twoFactorAuthentication()) && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return to_route('two-factor.login');
        }

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * Go back with an error next to the provider buttons.
     */
    protected function failed(string $route, string $message): RedirectResponse
    {
        return to_route($route)->withErrors(['social' => $message]);
    }

    /**
     * The Socialite driver, sending people back to our callback route.
     */
    protected function driver(SocialProvider $provider): Provider
    {
        $driver = Socialite::driver($provider->driver());

        return $driver instanceof AbstractProvider ? $driver->redirectUrl(route('social.callback', $provider)) : $driver;
    }
}
