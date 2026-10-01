<?php

namespace App\Http\Middleware;

use App\Models\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an admin is signed in as someone else (USR-003), they can't change how that person signs in or delete them.
 */
class BlockWhileImpersonating
{
    /**
     * Routes that change the account's sign-in or remove it.
     */
    public const BLOCKED_ROUTES = [
        'profile.update',
        'profile.destroy',
        'user-password.update',
        'social-accounts.destroy',
        'social.redirect',
        'two-factor.enable',
        'two-factor.disable',
        'two-factor.confirm',
        'two-factor.regenerate-recovery-codes',
        'passkey.store',
        'passkey.destroy',
        'users.impersonate',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY)
            && in_array($request->route()?->getName(), self::BLOCKED_ROUTES, true)) {
            abort(403, __('You can’t change this while impersonating.'));
        }

        return $next($request);
    }
}
