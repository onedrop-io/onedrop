<?php

use App\Http\Middleware\EnsureAgentConnected;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\UseBuiltAssetsForRemoteRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: ['sandbox-events/*']);

        // Saved file contents must reach the sandbox byte for byte (trailing newlines included).
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->isMethod('PUT') && $request->is('projects/*/files'),
            fn (Request $request) => $request->is('projects/*/database/*'),
        ]);

        // Database cells keep the difference between an empty string and NULL.
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('projects/*/database/*'),
        ]);

        // A local reverse proxy (e.g. `tailscale funnel`) forwards the real scheme and host.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->alias([
            'agent.connected' => EnsureAgentConnected::class,
        ]);

        $middleware->web(append: [
            UseBuiltAssetsForRemoteRequests::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
