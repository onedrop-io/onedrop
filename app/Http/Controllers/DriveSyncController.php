<?php

namespace App\Http\Controllers;

use App\Models\DriveItem;
use App\Models\Sandbox;
use App\Sandbox\Drive\Drive;
use App\Sandbox\Drive\DriveException;
use App\Sandbox\Drive\DriveSpace;
use App\Sandbox\Drive\DriveSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API a sandbox's Drive daemon (docker/sandbox/drive.mjs) keeps its copy of Drive in step with (DRIVE-003,
 * DRIVE-004). It acts for the project's owner, in the places their computer or project may reach.
 */
class DriveSyncController extends Controller
{
    public function __construct(protected Drive $drive, protected DriveSync $sync) {}

    /**
     * The places to keep, and either what changed after revision `after`, or (with `space`) everything in one place
     * after item `after_id`, for a daemon starting over or given a new place.
     */
    public function state(Request $request, Sandbox $sandbox): JsonResponse
    {
        $places = $this->authorizeSandbox($request, $sandbox);
        $spaces = $places->pluck('space');

        if ($request->filled('space')) {
            $space = $this->drive->pick($spaces, (string) $request->query('space')) ?? abort(404);
            $cursor = $this->drive->currentRevision();
            $items = $this->drive->listing($space, (int) $request->query('after_id', 0));

            return response()->json([
                'places' => $places->map(fn (array $place) => ['key' => $place['key'], 'dir' => $place['dir']]),
                'items' => $items->map(fn (DriveItem $item) => $this->item($item, $spaces)),
                'cursor' => $cursor,
                'more' => $items->count() === 1000,
            ]);
        }

        $changes = $this->drive->changes($spaces, (int) $request->query('after', 0));

        return response()->json([
            'places' => $places->map(fn (array $place) => ['key' => $place['key'], 'dir' => $place['dir']]),
            'items' => $changes['items']->map(fn (DriveItem $item) => $this->item($item, $spaces)),
            'cursor' => $changes['cursor'],
            'more' => $changes['more'],
        ]);
    }

    /**
     * Make a folder (or find the one already there by that name).
     */
    public function folder(Request $request, Sandbox $sandbox): JsonResponse
    {
        $spaces = $this->authorizeSandbox($request, $sandbox)->pluck('space');
        $data = $request->validate(['space' => ['required', 'string'], 'parent_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:255']]);
        [$space, $parent] = $this->placeOf($spaces, $data);

        return $this->attempt(fn () => $this->item($this->drive->createFolder($space, $parent, $data['name'], $sandbox->project->user, reuse: true), $spaces));
    }

    /**
     * Start uploading a file of `size` bytes.
     */
    public function startUpload(Request $request, Sandbox $sandbox): JsonResponse
    {
        $this->authorizeSandbox($request, $sandbox);
        $data = $request->validate(['size' => ['required', 'integer', 'min:0']]);

        return $this->attempt(fn () => ['upload' => $this->drive->startUpload($sandbox->project->organization, (int) $data['size']), 'part_bytes' => Drive::PART_BYTES]);
    }

    /**
     * One part of an upload, as the request's body.
     */
    public function uploadPart(Request $request, Sandbox $sandbox, int $part): JsonResponse
    {
        $this->authorizeSandbox($request, $sandbox);

        return $this->attempt(function () use ($request, $part) {
            $this->drive->receivePart((string) $request->header('X-Onedrop-Upload'), $part, $request->getContent());

            return ['ok' => true];
        });
    }

    /**
     * Finish an upload as a file: new, or a new version of `item_id` based on its `base_revision`.
     */
    public function file(Request $request, Sandbox $sandbox): JsonResponse
    {
        $spaces = $this->authorizeSandbox($request, $sandbox)->pluck('space');
        $data = $request->validate([
            'upload' => ['required', 'string'],
            'space' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'item_id' => ['nullable', 'integer'],
            'base_revision' => ['nullable', 'integer'],
            'sha256' => ['nullable', 'string'],
        ]);
        [$space, $parent] = $this->placeOf($spaces, $data);
        $replacing = isset($data['item_id']) ? $this->itemIn($spaces, (int) $data['item_id']) : null;

        return $this->attempt(fn () => $this->item($this->drive->finishUpload(
            $data['upload'], $space, $parent, $data['name'], $sandbox->project->user,
            replacing: $replacing && $replacing->trashed_at === null ? $replacing : null,
            // A new file whose name is taken was made at the same time somewhere else: keep both.
            baseRevision: $replacing ? ($data['base_revision'] ?? null) : -1,
            sha256: $data['sha256'] ?? null,
        ), $spaces));
    }

    /**
     * Rename or move an item, based on its `base_revision`.
     */
    public function update(Request $request, Sandbox $sandbox, int $item): JsonResponse
    {
        $spaces = $this->authorizeSandbox($request, $sandbox)->pluck('space');
        $data = $request->validate(['space' => ['required', 'string'], 'parent_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:255'], 'base_revision' => ['nullable', 'integer']]);
        [$space, $parent] = $this->placeOf($spaces, $data);
        $item = $this->itemIn($spaces, $item) ?? abort(404);

        return $this->attempt(fn () => $this->item($this->drive->move($item, $space, $parent, $data['name'], $sandbox->project->user, $data['base_revision'] ?? null), $spaces));
    }

    /**
     * Put an item in Trash (DRIVE-002).
     */
    public function destroy(Request $request, Sandbox $sandbox, int $item): JsonResponse
    {
        $spaces = $this->authorizeSandbox($request, $sandbox)->pluck('space');
        $item = $this->itemIn($spaces, $item);

        if ($item) {
            $this->drive->trash($item, $sandbox->project->user);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A file's contents.
     */
    public function content(Request $request, Sandbox $sandbox, int $item): Response
    {
        $spaces = $this->authorizeSandbox($request, $sandbox)->pluck('space');

        return $this->drive->contents($this->itemIn($spaces, $item) ?? abort(404), download: true);
    }

    /**
     * The sandbox's places, when the request has its token.
     *
     * @return Collection<int, array{key: string, dir: string, space: DriveSpace}>
     */
    protected function authorizeSandbox(Request $request, Sandbox $sandbox): Collection
    {
        abort_unless(DriveSync::accepts($sandbox, $request->bearerToken()) && $sandbox->project !== null, 403);

        return $this->sync->places($sandbox);
    }

    /**
     * The place and folder a request names.
     *
     * @param  Collection<int, DriveSpace>  $spaces
     * @param  array<string, mixed>  $data
     * @return array{0: DriveSpace, 1: DriveItem|null}
     */
    protected function placeOf(Collection $spaces, array $data): array
    {
        $space = $this->drive->pick($spaces, (string) $data['space']) ?? abort(404);
        $parent = isset($data['parent_id']) ? DriveItem::query()->in($space)->find($data['parent_id']) : null;

        abort_if(isset($data['parent_id']) && $parent === null, 409, __('That folder no longer exists.'));

        return [$space, $parent];
    }

    /**
     * An item in one of the places, trashed or not.
     *
     * @param  Collection<int, DriveSpace>  $spaces
     */
    protected function itemIn(Collection $spaces, int $id): ?DriveItem
    {
        $item = DriveItem::query()->find($id);

        return $item && $this->drive->spaceOf($item, $spaces) ? $item : null;
    }

    /**
     * What the daemon knows of an item.
     *
     * @param  Collection<int, DriveSpace>  $spaces
     * @return array<string, mixed>
     */
    protected function item(DriveItem $item, Collection $spaces): array
    {
        $space = $this->drive->spaceOf($item, $spaces);

        return [
            'id' => $item->id,
            // Null when it moved to a place this sandbox doesn't keep: gone from here.
            'space' => $space?->key(),
            'parent_id' => $item->parent_id,
            'name' => $item->name,
            'folder' => $item->is_folder,
            'size' => $item->size,
            'sha256' => $item->sha256,
            'revision' => $item->revision,
            'trashed' => $item->trashed_at !== null,
        ];
    }

    /**
     * Run a Drive change, answering what it refused with its reason.
     *
     * @param  callable(): array<string, mixed>  $change
     */
    protected function attempt(callable $change): JsonResponse
    {
        try {
            return response()->json($change());
        } catch (DriveException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
