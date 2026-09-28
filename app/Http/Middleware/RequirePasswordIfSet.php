<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

/**
 * Ask for the user's password again, unless they don't have one (they only
 * log in with Google, GitHub, etc.) and so have nothing to confirm.
 */
class RequirePasswordIfSet extends RequirePassword
{
    /**
     * Determine if the confirmation timeout has expired.
     *
     * @param  Request  $request
     * @param  int|null  $passwordTimeoutSeconds
     */
    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null): bool
    {
        if ($request->user() && ! $request->user()->hasPassword()) {
            return false;
        }

        return parent::shouldConfirmPassword($request, $passwordTimeoutSeconds);
    }
}
