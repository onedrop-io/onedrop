<?php

namespace App\Sandbox;

/**
 * The provider no longer has the sandbox at all (its Docker container was removed outside the app, e.g. by
 * `docker system prune`): waking it can never work, so it's replaced (SBX-016).
 */
class SandboxGone extends SandboxException {}
