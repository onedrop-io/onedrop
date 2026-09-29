<?php

namespace App\Enums;

/**
 * The board column a task is in (TASK-002).
 */
enum TaskStage: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::Review => 'Review',
            self::Done => 'Done',
        };
    }
}
