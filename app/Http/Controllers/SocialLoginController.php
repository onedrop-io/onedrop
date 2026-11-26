<?php

namespace App\Http\Controllers;

use App\Actions\Auth\ResolveSocialUser;
use App\Enums\SocialProvider;
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
 * Log in, sign up, or connect an account with Google, Apple, Microsoft,
 * GitHub, GitLab, or an OIDC issuer. Signed-in users use the same flow to connect a
 * provider to their account. One provider account can be connected to several logins; signing in with it then asks
 * which one to continue as (AUTH-003).
 */
class SocialLoginController extends Controller
{
    /** The session key holding the logins a provider sign-in may continue as (AUTH-003). */
    public const CHOICES = 'social.choices';

    /** How long the choice is offered. */
    public const CHOICE_MINUTES = 10;

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
        // A GitHub App whose Callback URL is this login one: its return from a Source Control connection goes on there.
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
            $users = $resolve->handle($provider, $identity);
        } catch (ValidationException $e) {
            return to_route('login')->withErrors($e->errors());
        }

        if ($users->count() > 1) {
            $request->session()->put(self::CHOICES, [
                'provider' => $provider->value,
                'users' => $users->modelKeys(),
                'expires_at' => now()->addMinutes(self::CHOICE_MINUTES)->getTimestamp(),
            ]);

            return to_route('social.choose');
        }

        return $this->logIn($request, $users->sole(), $provider);
    }

    /**
     * Ask which login to continue as, when the provider account is connected to several (AUTH-003).
     */
    public function choose(Request $request): Response
    {
        $choices = $this->choices($request);

        if ($choices === null) {
            return to_route('login');
        }

        return Inertia::render('auth/choose-account', [
            'provider' => $choices['provider']->label(),
            'accounts' => User::with('organizations:id,name')->whereKey($choices['users'])->orderBy('id')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'organizations' => $user->organizations->pluck('name')->all(),
                ]),
        ])->toResponse($request);
    }

    /**
     * Continue as the chosen login, one of those the provider account is connected to.
     */
    public function chosen(Request $request): RedirectResponse
    {
        $choices = $this->choices($request);

        if ($choices === null) {
            return $this->failed('login', __('That sign-in has expired. Try again.'));
        }

        $id = $request->integer('user');

        abort_unless(in_array($id, $choices['users'], true), 403);

        $request->session()->forget(self::CHOICES);

        return $this->logIn($request, User::findOrFail($id), $choices['provider']);
    }

    /**
     * The logins this session's provider sign-in may continue as, while it hasn't expired.
     *
     * @return array{provider: SocialProvider, users: list<int>}|null
     */
    protected function choices(Request $request): ?array
    {
        $choices = $request->session()->get(self::CHOICES);

        if (! is_array($choices) || ($choices['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::CHOICES);

            return null;
        }

        $provider = SocialProvider::tryFrom((string) ($choices['provider'] ?? ''));

        return $provider === null ? null : ['provider' => $provider, 'users' => array_map('intval', $choices['users'] ?? [])];
    }

    /**
     * Pass Apple's posted sign-in on to the callback as a visit, which brings the session cookie (SameSite=Lax) that
     * its cross-site post didn't.
     */
    public function relay(Request $request): RedirectResponse
    {
        $query = http_build_query($request->only(['code', 'state', 'user', 'error']));

        return redirect()->to(route('social.callback', SocialProvider::Apple).'?'.$query, 303);
    }

    /**
     * Connect the provider to the signed-in user.
     */
    protected function connect(User $user, SocialProvider $provider, SocialiteUser $identity, ResolveSocialUser $resolve): RedirectResponse
    {
        // The same provider account may be connected to the person's other logins too (AUTH-003).
        $resolve->link($user, $provider, $identity);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider connected.', ['provider' => $provider->label()])]);

        return to_route('security.edit');
    }

    /**
     * Log the user in, asking for their two-factor code first if they use one.
     */
    protected function logIn(Request $request, User $user, SocialProvider $provider): RedirectResponse
    {
        if (Features::enabled(Features::twoFactorAuthentication()) && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false, 'login.method' => $provider->value]);

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

        return $driver instanceof AbstractProvider ? $driver->redirectUrl(route('social.callback', $provider))->scopes($provider->scopes()) : $driver;
    }
}
