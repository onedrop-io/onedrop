<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\TemplateScreenshotController as WebController;

/**
 * A free app's pictures in the desktop app's app details (PRJ-012), as the web app's. Its own class so the web app's generated routes (Wayfinder) keep pointing at the web route alone.
 */
class TemplateScreenshotController extends WebController {}
