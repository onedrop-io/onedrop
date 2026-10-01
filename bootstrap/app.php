<?php

use App\Http\Middleware\BlockWhileImpersonating;
use App\Http\Middleware\EnsureAgentConnected;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\UseBuiltAssetsForRemoteRequests;
use App\Http\Middleware\UseTaskSandbox;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'sidebar_width', 'open_project']);
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

        // A local reverse proxy (Caddy, `tailscale funnel`) forwards the real scheme and host.
        // On Laravel Cloud, leave it to Laravel, which trusts Cloud's load balancer.
        if (! laravel_cloud()) {
            $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        }

        // It needs the address's organization bound to its model first.
        $middleware->appendToPriorityList(SubstituteBindings::class, ResolveOrganization::class);

        $middleware->alias([
            'agent.connected' => EnsureAgentConnected::class,
            'organization' => ResolveOrganization::class,
        ]);

        $middleware->web(append: [
            UseBuiltAssetsForRemoteRequests::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            BlockWhileImpersonating::class,
            UseTaskSandbox::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
