<?php

namespace App\Sandbox;

/**
 * A provider refusal that clears by itself (no room for now, the sandbox busy, no answer in the time a step had):
 * a move tries the step again a little later instead of failing (SBX-005).
 */
class TemporarySandboxException extends SandboxException {}
