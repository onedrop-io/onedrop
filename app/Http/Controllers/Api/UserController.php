<?php

namespace App\Http\Controllers\Api;

use App\Concerns\DescribesRealtime;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use App\Sandbox\Agents\AiCredits;
use App\Sandbox\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserController extends Controller
{
    use DescribesRealtime;

    /**
     * Who the desktop app is signed in as, and the install it's signed in to (DESK-001).
     */
    public function show(Request $request, Branding $branding): JsonResponse
    {
        $user = $request->user();
        $logo = $branding->logoUrl();

        return response()->json([
            'user' => [
                ...$user->only('id', 'name', 'email', 'avatar', 'is_admin'),
                // Whether the user can build: their own AI, or the install's AI credits (CREDIT-001). Without either the
                // projects API refuses (409): set one up on the web first (AI-001).
                'ai_connected' => $user->agentConnections()->exists() || app(AiCredits::class)->enabled(),
            ],
            'app' => [
                'name' => config('app.name'),
                'url' => url('/'),
                'logo' => $logo !== null ? url($logo) : null,
            ],
            // The organization the app works in, and the others it can switch to (ORG-002).
            'organization' => ResolveOrganization::current($request)->only('id', 'name', 'slug'),
            'organizations' => $user->organizations()->orderBy('name')->get(['organizations.id', 'name', 'slug'])
                ->map(fn (Organization $organization): array => $organization->only('id', 'name', 'slug'))->all(),
            'realtime' => $this->realtime(),
        ]);
    }

    /**
     * Work in another of the user's organizations: its projects from now on, here and where they sign in next.
     */
    public function switchOrganization(Request $request): Response
    {
        $organization = Organization::find($request->integer('organization'));

        abort_unless($organization && $request->user()->belongsToOrganization($organization), 404);

        $request->user()->switchOrganization($organization);

        return response()->noContent();
    }
}
