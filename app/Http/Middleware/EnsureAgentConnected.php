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
            // The desktop app can't show onboarding: it sends the user to the web for it (DESK-001).
            abort_if($request->is('api/*'), 409, __('Set up AI on the web first.'));

            return to_route('onboarding.ai');
        }

        return $next($request);
    }
}
