<?php

namespace App\Sandbox;

/**
 * The user's GitHub sign-in through the app is missing or lapsed; they need to reconnect GitHub.
 */
class GitHubSignInNeeded extends GitException {}
