<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\AcceptInvitationController;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! User::setupAllowed()) {
            throw ValidationException::withMessages(['email' => __('To create the first account, open the setup link the installer printed.')]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        if (! config('auth.verify_email')) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->becomeAdminIfFirst();

        $this->acceptInvitation($user);

        return $user;
    }

    /**
     * Mark the invite this person arrived with (if any) as used.
     */
    protected function acceptInvitation(User $user): void
    {
        $id = session()->pull(AcceptInvitationController::SESSION_KEY);

        if (is_int($id) || is_string($id)) {
            Invitation::find($id)?->acceptFor($user);
        }
    }
}
