<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class UseBuiltAssetsForRemoteRequests
{
    /**
     * The Vite dev server (`npm run dev`) only listens on this machine. When the app
     * is opened from elsewhere (e.g. a Tailscale Funnel), serve the built assets
     * (`npm run build`) instead so pages still load their JavaScript.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! static::isLocalHost($request->getHost())) {
            Vite::useHotFile(storage_path('framework/vite-hot-disabled-for-remote-requests'));
        }

        return $next($request);
    }

    /**
     * Whether a hostname refers to this machine.
     */
    public static function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
    }
}
