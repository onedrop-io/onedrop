<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\SandboxActivityController as WebController;

/**
 * Keeps the sandbox awake while the desktop app shows the project (SBX-007), as the web app's. Its own class so the web app's generated routes (Wayfinder) keep pointing at the web route alone.
 */
class SandboxActivityController extends WebController {}
