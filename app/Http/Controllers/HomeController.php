<?php

namespace App\Http\Controllers;

use App\Enums\AppTemplate;
use App\Sandbox\Templates\TemplateCatalog;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The marketing home page (HOME-001), with the new-project page's ways to start (HOME-004).
     */
    public function __invoke(TemplateCatalog $templates): Response
    {
        return Inertia::render('welcome', [
            'templates' => AppTemplate::options(),
            'apps' => Inertia::defer(fn () => $templates->apps()),
            'featured' => Inertia::defer(fn () => $templates->featured(), 'featured'),
            'compose' => $templates->canRunCompose(),
        ]);
    }
}
