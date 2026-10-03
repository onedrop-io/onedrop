<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Str;

class UpdateProject
{
    /**
     * Rename, pin, mark unread, or archive the project from its menu. None of these move it in "Recent".
     *
     * @param  array{name?: string, pinned?: bool|string|int, archived?: bool|string|int, unread?: bool|string|int}  $input  Only the keys being changed.
     */
    public function handle(Project $project, array $input): void
    {
        $changes = [];

        if (array_key_exists('name', $input)) {
            $changes['name'] = Str::squish((string) $input['name']);
        }

        foreach (['pinned' => 'pinned_at', 'archived' => 'archived_at'] as $key => $column) {
            if (array_key_exists($key, $input)) {
                $changes[$column] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN) ? ($project->{$column} ?? now()) : null;
            }
        }

        $unread = array_key_exists('unread', $input) ? filter_var($input['unread'], FILTER_VALIDATE_BOOLEAN) : null;

        if ($unread !== null) {
            $changes['read_at'] = $unread ? null : now();
        }

        Project::withoutTimestamps(fn () => $project->update($changes));

        // Marking read clears its tasks' dots too, since they make the project unread (PRJ-008).
        if ($unread === false) {
            Task::withoutTimestamps(fn () => $project->tasks()->update(['read_at' => now()]));
        }
    }
}
