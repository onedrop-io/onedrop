<?php

namespace App\Http\Controllers;

use App\Enums\AppTemplate;
use App\Models\Organization;
use App\Sandbox\Templates\TemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The marketing home page (HOME-001), with the new-project page's ways to start (HOME-004). Only the hosted
     * install has one: a self-hosted install's address goes straight to logging in, or to the dashboard (HOME-005).
     */
    public function __invoke(Request $request, TemplateCatalog $templates): Response|RedirectResponse
    {
        if (! Organization::multiTenant()) {
            return redirect()->route($request->user() ? 'dashboard' : 'login');
        }

        return Inertia::render('welcome', [
            'templates' => AppTemplate::options(),
            'apps' => Inertia::defer(fn () => $templates->apps()),
            'featured' => Inertia::defer(fn () => $templates->featured(), 'featured'),
            'compose' => $templates->canRunCompose(),
        ]);
    }
}
