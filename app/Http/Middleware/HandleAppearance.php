<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appearance = $request->cookie('appearance');
        $appearance = in_array($appearance, ['light', 'dark', 'system'], true) ? $appearance : 'dark';

        View::share('appearance', $appearance);
        View::share('themeLight', $this->theme($request, 'light'));
        View::share('themeDark', $this->theme($request, 'dark'));

        return $next($request);
    }

    /**
     * The theme picked for light or dark mode (SET-002), or OneDrop's own. Only the id's shape is checked here; an id
     * with no colors falls back to OneDrop's in the CSS, and the app corrects the cookie when it loads.
     */
    private function theme(Request $request, string $kind): string
    {
        $theme = $request->cookie("theme-{$kind}");

        return is_string($theme) && preg_match('/^[a-z0-9-]{1,40}$/', $theme) ? $theme : "onedrop-{$kind}";
    }
}
