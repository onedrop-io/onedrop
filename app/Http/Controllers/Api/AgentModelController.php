<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\AgentModelController as WebController;

/**
 * The models the desktop app's picker offers (AGT-002), as the web app's. Its own class so the web app's generated routes (Wayfinder) keep pointing at the web route alone.
 */
class AgentModelController extends WebController {}
