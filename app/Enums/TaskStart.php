<?php

namespace App\Enums;

/**
 * Where a task's copy of the app starts (TASK-005).
 */
enum TaskStart: string
{
    /** A fresh clone of a branch on the project's remote: the code alone, and the app sets itself up. */
    case Branch = 'branch';

    /** A copy of Main's sandbox: its files, uncommitted changes, dependencies and data. */
    case Main = 'main';

    /** A copy of another task's copy, the same way. */
    case Task = 'task';
}
