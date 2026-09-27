<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Settings\AgentConnectionController;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Ask a new user to connect their AI.
     */
    public function ai(Request $request): Response
    {
        return Inertia::render('onboarding/ai', [
            'connections' => AgentConnectionController::connectionsFor($request->user()),
        ]);
    }
}
