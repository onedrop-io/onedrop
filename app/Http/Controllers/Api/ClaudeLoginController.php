<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ClaudeLoginController as WebController;

/**
 * Whether Claude Code is signed in to the user's Claude subscription in the sandbox, and carrying on once it is
 * (AI-005), for the desktop app, as the web app's. Its own class so the web app's generated routes (Wayfinder) keep
 * pointing at the web route alone.
 */
class ClaudeLoginController extends WebController {}
