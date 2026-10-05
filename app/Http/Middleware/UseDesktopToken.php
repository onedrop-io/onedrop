<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The desktop app runs the web app's own pages over HTTP with its API token (DESK-001), instead of a session cookie
 * a browser keeps. A request with a valid token is that token's user, with a session of its own named after the
 * token, so what the web keeps between requests (a flashed toast, where to go back to) works for the app too.
 * Runs before the session starts; a request without a token is left as it is.
 */
class UseDesktopToken
{
    /** The request attribute holding the desktop app's token. */
    public const ATTRIBUTE = 'desktop_token';

    /** The web app's UI cookies the desktop app sends along (they're left unencrypted in bootstrap/app.php). */
    public const UI_COOKIES = ['appearance', 'sidebar_state', 'sidebar_width', 'open_project'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain !== null ? PersonalAccessToken::findToken($plain) : null;

        $user = $token?->tokenable;

        if ($token !== null && $user instanceof User && ($token->expires_at === null || $token->expires_at->isFuture())) {
            $request->cookies->set((string) config('session.cookie'), self::sessionId($token));
            $this->useUiCookies($request);
            $request->attributes->set(self::ATTRIBUTE, $token);
            // The token stays the user's current one, so pages know which computer this is (DESK-006..010).
            Auth::guard('web')->setUser($user->withAccessToken($token));

            $token->forceFill(['last_used_at' => now()])->save();
        }

        $response = $next($request);

        // When this server's release was made, for the app to tell whether the server is older than it (DESK-004).
        if (self::from($request) && config('app.released_at') !== null) {
            $response->headers->set('X-Onedrop-Released', (string) config('app.released_at'));
        }

        return $response;
    }

    /**
     * The web app's UI cookies (the open project, the sidebar, appearance) live on the desktop app's own origin, which
     * the server's cookies can't reach, so it sends them in X-Onedrop-Cookies. Only these: they aren't encrypted, and
     * the session's comes from the token.
     */
    protected function useUiCookies(Request $request): void
    {
        foreach (explode(';', (string) $request->header('X-Onedrop-Cookies')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if (in_array($name, self::UI_COOKIES, true)) {
                $request->cookies->set($name, urldecode($value));
            }
        }
    }

    /**
     * Whether the request comes from the desktop app.
     */
    public static function from(Request $request): bool
    {
        return $request->attributes->has(self::ATTRIBUTE);
    }

    /**
     * The token's session id: one per token, and nobody without the app's key can work it out.
     */
    public static function sessionId(PersonalAccessToken $token): string
    {
        return substr(hash_hmac('sha256', 'desktop-session:'.$token->getKey(), (string) config('app.key')), 0, 40);
    }
}
