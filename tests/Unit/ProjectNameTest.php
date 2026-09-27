<?php

use App\Models\Project;

test('project names come from the first words of the description', function (string $prompt, string $name) {
    expect(Project::nameFromPrompt($prompt))->toBe($name);
})->with([
    'short' => ['time tracker like toggl', 'Time Tracker Like Toggl'],
    'long is cut to six words' => ['build me a simple app to track expenses for the team', 'Build Me A Simple App To'],
    'trailing punctuation' => ['a CRM!', 'A Crm'],
    'extra whitespace' => ["  a   vacation\ncalendar ", 'A Vacation Calendar'],
    'blank' => ['   ', 'Untitled Project'],
])->group('PRJ-001');
