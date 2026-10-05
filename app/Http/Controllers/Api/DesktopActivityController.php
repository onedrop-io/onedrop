<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Enums\TaskStage;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopActivityController extends Controller
{
    /**
     * The user's projects whose agents are working or waiting for them, for the desktop app's menu bar icon and
     * badge (DESK-011). A project counts once, for its main chat or any open task.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $projects = $request->user()->projects()
            ->whereNull('archived_at')
            ->with(['tasks' => fn ($query) => $query->where('stage', '!=', TaskStage::Done)])
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->map(function (Project $project): array {
                $conversations = collect([$project, ...$project->tasks]);

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'url' => route('projects.show', $project, false),
                    'working' => $conversations->contains(fn (Project|Task $conversation): bool => $conversation->status === ProjectStatus::Working),
                    'waiting_for' => $conversations->map(fn (Project|Task $conversation): ?string => $conversation->status !== ProjectStatus::Working && $conversation->turn_outcome?->waiting()
                        ? $conversation->turn_outcome->value
                        : null)->filter()->first(),
                ];
            })
            ->filter(fn (array $project): bool => $project['working'] || $project['waiting_for'] !== null)
            ->values();

        return response()->json(['projects' => $projects]);
    }
}
