<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ProjectAttachmentController as WebController;

/**
 * Opens a message's attachment in the desktop app, as the web app's. Its own class so the web app's generated routes (Wayfinder) keep pointing at the web route alone.
 */
class ProjectAttachmentController extends WebController {}
