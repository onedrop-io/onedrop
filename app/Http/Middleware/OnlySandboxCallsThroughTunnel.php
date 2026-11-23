<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OnlySandboxCallsThroughTunnel
{
    /**
     * The tunnel `sandbox:tunnel` opens (SBX-015) is public, so through it the app answers only what sandboxes
     * call: agent events, the file watcher, `ask`, git's credential helper, Drive, and "Sign in with OneDrop". Not the dev login or any page.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            str_ends_with($request->getHost(), '.trycloudflare.com')
                && ! $request->is('sandbox-events/*', 'sandbox-ai/*', 'sandbox-git/*', 'drive-sync/*', 'oauth/token', 'oauth/userinfo'),
            404,
        );

        return $next($request);
    }
}
