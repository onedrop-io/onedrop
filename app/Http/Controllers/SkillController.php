<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Models\User;
use App\Sandbox\SkillDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One of a user's agent skills (SKILL-001): reading, editing, sharing and deleting it.
 */
class SkillController extends Controller
{
    /**
     * Its instructions and the names of its other files.
     */
    public function show(Request $request, Skill $skill): JsonResponse
    {
        Gate::authorize('view', $skill);

        $document = SkillDocument::parse($skill->content);

        return response()->json(['skill' => [
            ...self::present($skill->load('user:id,name'), $request->user()),
            'content' => $document['body'],
            'files' => array_column($skill->files ?? [], 'path'),
        ]]);
    }

    /**
     * Change its name, description, instructions, or whether it's shared. Stopping sharing turns it off in other
     * people's projects.
     */
    public function update(Request $request, Skill $skill): JsonResponse
    {
        Gate::authorize('update', $skill);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.SkillDocument::MAX_NAME],
            'description' => ['sometimes', 'required', 'string', 'max:'.SkillDocument::MAX_DESCRIPTION],
            'instructions' => ['sometimes', 'required', 'string', 'max:200000'],
            'shared' => ['sometimes', 'boolean'],
        ]);

        $name = $data['name'] ?? $skill->name;
        $description = trim($data['description'] ?? $skill->description);
        $problem = SkillDocument::problem($name, $description)
            ?? ($name !== $skill->name && $skill->user->skills()->inOrganization($skill->organization)->where('name', $name)->exists()
                ? __('You already have a skill named ":name".', ['name' => $name])
                : null);

        if ($problem) {
            return response()->json(['message' => $problem, 'errors' => ['name' => [$problem]]], 422);
        }

        $body = $data['instructions'] ?? SkillDocument::parse($skill->content)['body'];

        $skill->update([
            'name' => $name,
            'description' => $description,
            'content' => SkillDocument::withFields($skill->content, $name, $description, $body),
            'shared' => $data['shared'] ?? $skill->shared,
        ]);

        if (! $skill->shared) {
            $skill->projects()->detach($skill->projects()->where('projects.user_id', '!=', $skill->user_id)->pluck('projects.id'));
        }

        return response()->json(['skill' => self::present($skill->load('user:id,name'), $request->user())]);
    }

    /**
     * Delete it, which turns it off everywhere.
     */
    public function destroy(Skill $skill): JsonResponse
    {
        Gate::authorize('delete', $skill);

        $skill->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * A skill as the Agent Skills panel shows it.
     *
     * @return array{id: int, name: string, description: string, shared: bool, source: string, source_url: string|null, owner: string|null, mine: bool, can_edit: bool, updated_at: string|null}
     */
    public static function present(Skill $skill, User $user): array
    {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => $skill->description,
            'shared' => $skill->shared,
            'source' => $skill->source->value,
            'source_url' => $skill->source_url,
            'owner' => $skill->user?->name,
            'mine' => $skill->user_id === $user->id,
            'can_edit' => $user->can('update', $skill),
            'updated_at' => $skill->updated_at?->toIso8601String(),
        ];
    }
}
