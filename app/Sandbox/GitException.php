<?php

namespace App\Sandbox;

use RuntimeException;

/**
 * A git request was refused or failed (nothing to commit, a bad branch name, a rejected push).
 * The message is written for the user.
 */
class GitException extends RuntimeException {}
