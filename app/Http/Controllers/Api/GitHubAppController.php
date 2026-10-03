<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\GitHubAppController as WebController;

/**
 * The user's GitHub repositories for importing one into a new project (PRJ-009) in the desktop app, as the web
 * app's. Its own class so the web app's generated routes (Wayfinder) keep pointing at the web route alone.
 */
class GitHubAppController extends WebController {}
