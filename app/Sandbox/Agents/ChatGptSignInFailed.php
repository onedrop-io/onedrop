<?php

namespace App\Sandbox\Agents;

use RuntimeException;

/**
 * Signing in with ChatGPT, or refreshing that sign-in, didn't work. The message is safe to show the user.
 */
class ChatGptSignInFailed extends RuntimeException
{
    /**
     * OpenAI turned the sign-in down, so it needs signing in again (rather than OpenAI being unreachable for now).
     */
    public bool $expired = false;

    public static function expired(string $message): self
    {
        $exception = new self($message);
        $exception->expired = true;

        return $exception;
    }
}
