<?php

namespace App\Http\Middleware;

use App\Sandbox\SandboxWaitLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitSandboxWaits
{
    public function __construct(protected SandboxWaitLimit $limit) {}

    /**
     * Give the request's calls to the sandbox provider a time limit, so they fail with a message before the proxy
     * in front of the app gives up on the request (`sandbox.web_wait_seconds`).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (($seconds = (int) config('sandbox.web_wait_seconds')) > 0) {
            $this->limit->start($seconds);
        }

        return $next($request);
    }
}
