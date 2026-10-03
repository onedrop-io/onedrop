<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class BroadcastingController extends Controller
{
    /**
     * Let the desktop app listen to a private channel it's allowed to (LIVE-001), as `/broadcasting/auth` does for
     * the web app's session.
     */
    public function authenticate(Request $request): mixed
    {
        return Broadcast::auth($request);
    }
}
