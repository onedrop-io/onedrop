<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectAttachmentController extends Controller
{
    /**
     * Open a file attached to one of the project's messages. Images a browser can show open inline;
     * anything else downloads. Uploaded content never runs as a page (CSP sandbox, no sniffing).
     */
    public function show(Project $project, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $project);

        abort_unless($attachment->message?->project_id === $project->id, 404);

        return Storage::disk(Attachment::DISK)->response(
            $attachment->path,
            $attachment->name,
            [
                'Content-Type' => $attachment->isVisibleImage() ? $attachment->mime_type : 'application/octet-stream',
                'Content-Security-Policy' => 'sandbox',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=86400',
            ],
            $attachment->isVisibleImage() ? 'inline' : 'attachment',
        );
    }
}
