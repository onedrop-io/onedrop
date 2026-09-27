<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentConnected
{
    /**
     * Send users without a connected AI to onboarding.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->agentConnections()->exists()) {
            return to_route('onboarding.ai');
        }

        return $next($request);
    }
}
