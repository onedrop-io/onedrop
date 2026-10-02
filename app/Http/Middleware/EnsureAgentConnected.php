<?php

namespace App\Http\Middleware;

use App\Sandbox\Agents\AiCredits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentConnected
{
    /**
     * Send users without a connected AI to onboarding, unless the install offers AI credits to build with (CREDIT-001).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->agentConnections()->exists() && ! app(AiCredits::class)->enabled()) {
            return to_route('onboarding.ai');
        }

        return $next($request);
    }
}
