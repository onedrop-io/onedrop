<?php

namespace App\Http\Controllers;

use App\Models\Sandbox;
use App\Sandbox\Agents\OpenCodeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook for the agent forwarder running inside a sandbox.
 */
class SandboxEventController extends Controller
{
    /**
     * Store a batch of agent events, in order.
     */
    public function store(Request $request, Sandbox $sandbox, OpenCodeEvents $events): JsonResponse
    {
        abort_unless($sandbox->acceptsEventsToken($request->bearerToken()), 401);

        $validated = $request->validate([
            'events' => ['required', 'array', 'max:500'],
            'events.*' => ['array'],
        ]);

        foreach ($validated['events'] as $event) {
            $events->apply($sandbox->project, $event);
        }

        return response()->json(['ok' => true]);
    }
}
