<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildSandboxTemplate;
use App\Sandbox\GitHubActionsToken;
use App\Sandbox\SandboxTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The `sandbox image` workflow says a new sandbox image was published (SBX-014): providers whose image the app builds
 * from it (E2B) build it again, at the size set in Settings → Sandboxes. It proves where it's from with a GitHub
 * Actions OIDC token.
 */
class SandboxImageController extends Controller
{
    public function published(Request $request, GitHubActionsToken $tokens, SandboxTemplates $templates): JsonResponse
    {
        abort_unless($tokens->verify((string) $request->bearerToken()) !== null, 401);

        $building = collect(SandboxTemplates::PROVIDERS)->filter(fn (string $name) => $templates->builds($name))->values()->all();

        foreach ($building as $name) {
            BuildSandboxTemplate::dispatch($name);
        }

        return response()->json(['building' => $building], 202);
    }
}
