<?php

namespace App\Enums;

/**
 * How an agent's turn ended, as Jev judged it from the agent's last reply (PRJ-011): done, or waiting for the user.
 */
enum TurnOutcome: string
{
    case Done = 'done';
    case Question = 'question';
    case NeedsInput = 'needs_input';
    case Blocked = 'blocked';

    /**
     * What each outcome means, for Jev to choose from.
     *
     * @return array<string, string>
     */
    public static function criteria(): array
    {
        return [
            self::Done->value => "It did what was asked (or answered the user's question) and needs nothing more from the user now; offering optional next steps still counts as done.",
            self::Question->value => 'It ends by asking the user a question it needs answered before it can go on, such as which option they want or what they meant.',
            self::NeedsInput->value => "It needs the user to do or provide something it can't do itself: a secret or API key, signing in to a service, paying for something, or a decision only they can make.",
            self::Blocked->value => "It couldn't finish: it hit an error it couldn't fix, gave up, or stopped partway, without asking the user anything.",
        ];
    }

    /**
     * Whether the agent is waiting on the user, rather than done.
     */
    public function waiting(): bool
    {
        return $this !== self::Done;
    }
}
