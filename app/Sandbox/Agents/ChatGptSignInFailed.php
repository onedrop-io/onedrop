<?php

namespace App\Sandbox\Agents;

use RuntimeException;

/**
 * Signing in with ChatGPT, or refreshing that sign-in, didn't work. The message is safe to show the user.
 */
class ChatGptSignInFailed extends RuntimeException {}
