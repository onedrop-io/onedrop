<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;
use Illuminate\Http\Request;

/**
 * Laravel's CSRF check, except for the desktop app's requests (UseDesktopToken): a browser never sends its token on
 * its own, so a request that carries it can't have been forged by another site.
 */
class PreventRequestForgery extends Middleware
{
    /**
     * @param  Request  $request
     */
    public function handle($request, Closure $next)
    {
        if (UseDesktopToken::from($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
