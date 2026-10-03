<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\AiCreditsController as WebController;

/**
 * The organization's AI credits beside the desktop app's model picker (CREDIT-001), as the web app's. Its own class so the web app's generated routes (Wayfinder) keep pointing at the web route alone.
 */
class AiCreditsController extends WebController {}
