<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Models\ProjectShare;
use App\Sandbox\ShareCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A shared project's public page (SHARE-001) and "Remix this" (SHARE-002).
 */
class ShareController extends Controller
{
    /** Link preview fetchers and crawlers, which don't count as views. */
    protected const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|telegram|discord|slack|headless/i';

    public function show(Request $request, ProjectShare $share): Response
    {
        $project = $share->project;
        $isOwner = $request->user()?->id === $project->user_id;

        if (! $isOwner && ! preg_match(self::BOTS, (string) $request->userAgent())) {
            ShareCards::countView($share, $request->ip().'|'.$request->userAgent());
        }

        $live = $project->publish_status === PublishStatus::Live
            && $project->publish_visibility === PublishVisibility::Public
            && $project->published_url;
        $title = __(':name, built with OneDrop', ['name' => $project->name]);
        $description = Str::limit(Str::squish($share->prompt), 200);

        return Inertia::render('share/show', [
            'share' => [
                'slug' => $share->slug,
                'name' => $project->name,
                'author' => ShareCards::author($share),
                'prompt' => $share->prompt,
                'screenshot_url' => $share->screenshotUrl(),
                'prompts' => $project->allMessages()->where('role', MessageRole::User)->count(),
                'agent' => $project->agent_harness?->label(),
                'app_url' => $live ? $project->published_url : null,
                'url' => $share->url(),
                'shared_at' => $share->created_at?->toIso8601String(),
            ],
            'workspaceUrl' => $isOwner ? route('projects.show', $project) : null,
        ])->withViewData(['meta' => [
            'title' => $title,
            'description' => $description,
            'image' => $share->cardUrl() ?? asset('images/og.png'),
            'image_alt' => $share->card_file ? __(':name: “:prompt”', ['name' => $project->name, 'prompt' => Str::limit(Str::squish($share->prompt), 120)]) : null,
        ]]);
    }

    public function card(ProjectShare $share): HttpResponse
    {
        return $this->image($share->card_file);
    }

    public function screenshot(ProjectShare $share): HttpResponse
    {
        return $this->image($share->screenshot_file);
    }

    /**
     * Start a new project from the shared prompt: the new-project page opens with it filled in, after signing
     * up (or in) and setting up AI if needed.
     */
    public function remix(Request $request, ProjectShare $share): RedirectResponse
    {
        $share->timestamps = false;
        $share->increment('remixes');

        $request->session()->put('remix', ['name' => $share->project->name, 'prompt' => $share->prompt]);

        if ($request->user()) {
            return to_route('dashboard');
        }

        redirect()->setIntendedUrl(route('dashboard'));

        return to_route(Route::has('register') ? 'register' : 'login');
    }

    protected function image(?string $file): HttpResponse
    {
        abort_unless($file && Storage::disk(ShareCards::DISK)->exists($file), 404);

        return Storage::disk(ShareCards::DISK)->response($file, null, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
