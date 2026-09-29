<?php

namespace App\Actions\Auth;

use App\Enums\SocialProvider;
use App\Http\Controllers\AcceptInvitationController;
use App\Models\Invitation;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class ResolveSocialUser
{
    /**
     * Find the user a provider sign-in belongs to: an account already linked
     * to it, an existing user with the same (verified) email, or a new user
     * when registration is open.
     *
     * @throws ValidationException
     */
    public function handle(SocialProvider $provider, SocialiteUser $identity): User
    {
        $account = SocialAccount::with('user')
            ->where('provider', $provider)
            ->where('provider_id', (string) $identity->getId())
            ->first();

        if ($account) {
            $this->link($account->user, $provider, $identity);

            return $account->user;
        }

        $email = $identity->getEmail();

        if (blank($email)) {
            throw $this->failure(__(":provider didn't share an email address with us.", ['provider' => $provider->label()]));
        }

        $verified = $provider->emailIsVerified($identity);
        $existing = User::whereRaw('lower(email) = ?', [Str::lower($email)])->first();

        if ($existing) {
            // Only link when both sides have proven they own the address, so
            // nobody can take over an account with an unverified email.
            if (! $verified || $existing->email_verified_at === null) {
                throw $this->failure(__('An account for :email already exists. Log in to it, then connect :provider from your security settings.', [
                    'email' => $email,
                    'provider' => $provider->label(),
                ]));
            }

            $this->link($existing, $provider, $identity);

            return $existing;
        }

        if (! Features::enabled(Features::registration())) {
            throw $this->failure(__("There's no account for :email.", ['email' => $email]));
        }

        return $this->register($provider, $identity, $verified);
    }

    /**
     * Connect a provider account to a user (or refresh it on a later sign-in).
     */
    public function link(User $user, SocialProvider $provider, SocialiteUser $identity): SocialAccount
    {
        $previousAvatar = $user->socialAccounts()->where('provider', $provider)->value('avatar');

        $account = $user->socialAccounts()->updateOrCreate(
            ['provider' => $provider],
            ['provider_id' => (string) $identity->getId(), 'email' => $identity->getEmail(), 'avatar' => $identity->getAvatar() ?: null],
        );

        // Use the picture when the user has none, and keep it current when it came from this provider.
        if ($account->avatar && ($user->avatar === null || $user->avatar === $previousAvatar)) {
            $user->forceFill(['avatar' => $account->avatar])->save();
        }

        return $account;
    }

    /**
     * Create a password-less user from a provider identity.
     */
    protected function register(SocialProvider $provider, SocialiteUser $identity, bool $verified): User
    {
        if (! User::setupAllowed()) {
            throw $this->failure(__('To create the first account, open the setup link the installer printed.'));
        }

        $email = $identity->getEmail();

        $user = User::create([
            'name' => Str::limit($identity->getName() ?: $identity->getNickname() ?: Str::before($email, '@'), 255, ''),
            'email' => $email,
            'password' => null,
        ]);

        if ($verified || ! config('auth.verify_email')) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->becomeAdminIfFirst();

        $this->link($user, $provider, $identity);

        $invitationId = session()->pull(AcceptInvitationController::SESSION_KEY);

        if (is_int($invitationId) || is_string($invitationId)) {
            Invitation::find($invitationId)?->acceptFor($user);
        }

        event(new Registered($user));

        return $user;
    }

    /**
     * An error shown next to the provider buttons.
     */
    protected function failure(string $message): ValidationException
    {
        return ValidationException::withMessages(['social' => $message]);
    }
}
