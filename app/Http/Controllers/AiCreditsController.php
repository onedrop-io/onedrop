<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveOrganization;
use App\Sandbox\Agents\AiCredits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiCreditsController extends Controller
{
    /**
     * What the organization has left of its AI credits (CREDIT-001), for the balance beside the model picker.
     * Null when the install doesn't offer them or they can't be checked right now.
     */
    public function __invoke(Request $request, AiCredits $credits): JsonResponse
    {
        $organization = ResolveOrganization::current($request);

        return response()->json([
            'credits' => $credits->enabled() && $organization ? $credits->summary($organization) : null,
        ]);
    }
}
