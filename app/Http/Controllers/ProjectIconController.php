<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Jobs\UpdateProjectIcon;
use App\Models\Project;
use App\Sandbox\ProjectIcons;
use App\Sandbox\SandboxException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ProjectIconController extends Controller
{
    /**
     * The project's icon, also for anyone who sees the app on the Apps page (APPS-002). It may come from the app's own
     * files, so it's served so it can't run anything.
     */
    public function show(Project $project): Response
    {
        Gate::authorize('openApp', $project);

        abort_unless($project->icon_path && Storage::disk(ProjectIcons::disk())->exists($project->icon_path), 404);

        return Storage::disk(ProjectIcons::disk())->response($project->icon_path, null, [
            'Content-Type' => $project->icon_mime,
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * Replace the icon with an uploaded image, installing it in the app.
     */
    public function update(Request $request, Project $project, ProjectIcons $icons): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate([
            'icon' => ['required', 'file', 'max:'.intdiv(ProjectIcons::MAX_BYTES, 1024), 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml,text/xml,text/plain'],
        ], [
            'icon.mimetypes' => __('Upload a PNG, JPEG, WebP or SVG image.'),
            'icon.max' => __('The icon must be 512 KB or smaller.'),
        ]);

        $file = $request->file('icon');
        $mime = $file->getClientOriginalExtension() === 'svg' || str_contains((string) $file->getMimeType(), 'svg')
            ? 'image/svg+xml'
            : (string) $file->getMimeType();

        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'], true), 422, __('Upload a PNG, JPEG, WebP or SVG image.'));

        try {
            $icons->replace($project, (string) $file->get(), $mime);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], $project->sandbox?->status === SandboxStatus::Running ? 422 : 409);
        }

        return response()->json(['icon_url' => ProjectIcons::url($project->refresh())]);
    }

    /**
     * Have the project's AI draw a new icon, in the background.
     */
    public function draw(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        if ($project->sandbox?->status !== SandboxStatus::Running) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        ProjectIcons::markDrawing($project);
        UpdateProjectIcon::dispatch($project, redraw: true);

        return response()->json(['drawing' => true], 202);
    }
}
