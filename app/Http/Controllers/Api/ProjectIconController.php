<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ProjectIconController as WebController;

/**
 * The project's icon for the desktop app's sidebar, as the web app's. Its own class so the web app's generated routes (Wayfinder) keep pointing at the web route alone.
 */
class ProjectIconController extends WebController {}
