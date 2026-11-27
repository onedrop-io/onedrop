<?php

namespace App\Http\Middleware;

use App\Models\Impersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers when each person last used the app (USR-004), so admins can see who's using it. Background requests
 * (polls, partial reloads, prefetches, JSON reads) don't count, or a tab left open would look like someone working.
 */
class RecordUserActivity
{
    /** How stale `last_active_at` may get before a request writes it again. */
    public const EVERY_SECONDS = 300;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User && $this->countsAsUse($request)
            && ! ($request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY))
            && ($user->last_active_at === null || $user->last_active_at->lt(now()->subSeconds(self::EVERY_SECONDS)))) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $response;
    }

    /**
     * A page visit or something the person did, not a background refresh.
     */
    private function countsAsUse(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return true;
        }

        return ! $request->hasHeader('X-Inertia-Partial-Component')
            && $request->header('Purpose') !== 'prefetch'
            && ! ($request->expectsJson() && ! $request->hasHeader('X-Inertia'));
    }
}
