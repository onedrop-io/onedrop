<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'hasPassword' => $request->user()->hasPassword(),
            'socialAccounts' => $this->socialAccounts($request->user()),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Each provider the user can connect or has connected, and for GitHub whether it lets their projects download
     * their private packages (GIT-016): granted, or available by connecting or reconnecting.
     *
     * @return list<array{provider: string, label: string, account: array{id: int, email: string|null}|null, packages: 'granted'|'reconnect'|'connect'|null}>
     */
    protected function socialAccounts(User $user): array
    {
        $accounts = $user->socialAccounts()->get()->keyBy(fn (SocialAccount $account) => $account->provider->value);

        return array_values(collect(SocialProvider::cases())
            ->filter(fn (SocialProvider $provider) => $provider->isConfigured() || $accounts->has($provider->value))
            ->map(fn (SocialProvider $provider) => [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'account' => $accounts->has($provider->value)
                    ? ['id' => $accounts[$provider->value]->id, 'email' => $accounts[$provider->value]->email]
                    : null,
                'packages' => $provider === SocialProvider::GitHub ? $this->packages($accounts->get($provider->value)) : null,
            ])
            ->all());
    }

    /**
     * @return 'granted'|'reconnect'|'connect'|null
     */
    protected function packages(?SocialAccount $account): ?string
    {
        return match (true) {
            (bool) $account?->canReadPackages() => 'granted',
            ! SocialProvider::gitHubCanGrantPackages() => null,
            default => $account ? 'reconnect' : 'connect',
        };
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
