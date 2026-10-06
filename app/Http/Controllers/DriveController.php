<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveOrganization;
use App\Models\DriveItem;
use App\Sandbox\Drive\Drive;
use App\Sandbox\Drive\DriveException;
use App\Sandbox\Drive\DriveSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Drive (DRIVE-001, DRIVE-002): the user's My Drive, their organization's drive and their groups' drives, at
 * `/o/<slug>/drive`. Uploads come in parts (Drive::PART_BYTES) so any size passes through the app.
 */
class DriveController extends Controller
{
    public function __construct(protected Drive $drive) {}

    /**
     * Drive opens on My Drive.
     */
    public function index(Request $request): RedirectResponse
    {
        return to_route('drive.show', [ResolveOrganization::current($request), 'personal']);
    }

    /**
     * A place's folder (or its top), or its Trash with `?trash=1`.
     */
    public function show(Request $request, string $space, ?DriveItem $folder = null): Response
    {
        $organization = ResolveOrganization::current($request);
        $spaces = $this->spaces($request);
        $place = $this->drive->pick($spaces, $space) ?? abort(404);
        $trash = $request->boolean('trash');

        if ($folder !== null) {
            abort_unless($folder->is_folder && $folder->driveSpace()->is($place) && $this->drive->visible($folder), 404);
        }

        $items = $trash ? $this->drive->trashed($place) : $this->drive->children($place, $folder);
        $quota = $this->drive->quota();

        return Inertia::render('drive/show', [
            'spaces' => $spaces->map(fn (DriveSpace $candidate) => [
                'key' => $candidate->key(),
                'name' => $candidate->name,
                'kind' => $candidate->kind,
            ])->values(),
            'space' => ['key' => $place->key(), 'name' => $place->name, 'kind' => $place->kind],
            'folder' => $folder ? ['id' => $folder->id, 'name' => $folder->name] : null,
            'breadcrumbs' => $folder ? collect($this->drive->ancestors($folder))->map(fn (DriveItem $ancestor) => ['id' => $ancestor->id, 'name' => $ancestor->name])->values() : [],
            'trash' => $trash,
            'items' => $items->map(fn (DriveItem $item) => $this->item($item, $trash))->values(),
            'usage' => ['used' => $this->drive->usage($organization), 'quota' => $quota],
            'partBytes' => Drive::PART_BYTES,
            'maxFileBytes' => (int) config('drive.max_file_mb') * 1024 * 1024,
            'channel' => ['organization' => $organization->id, 'user' => $request->user()->id],
            'trashDays' => (int) config('drive.trash_days'),
        ]);
    }

    /**
     * The folders in a place's folder (or at its top), for picking where to move something.
     */
    public function folders(Request $request, string $space): JsonResponse
    {
        $place = $this->drive->pick($this->spaces($request), $space) ?? abort(404);
        $parent = $this->folderIn($place, $request->integer('parent') ?: null);

        return response()->json([
            'folders' => $this->drive->children($place, $parent)->where('is_folder', true)->map(fn (DriveItem $item) => ['id' => $item->id, 'name' => $item->name])->values(),
        ]);
    }

    /**
     * Make a folder; with `reuse`, one already there by that name is used (uploading a folder into one that exists).
     */
    public function storeFolder(Request $request, string $space): JsonResponse
    {
        $place = $this->drive->pick($this->spaces($request), $space) ?? abort(404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'parent_id' => ['nullable', 'integer'], 'reuse' => ['boolean']]);
        $parent = $this->folderIn($place, $data['parent_id'] ?? null);

        return $this->attempt(fn () => $this->item($this->drive->createFolder($place, $parent, $data['name'], $request->user(), reuse: (bool) ($data['reuse'] ?? false))));
    }

    /**
     * Start an upload of `size` bytes.
     */
    public function startUpload(Request $request): JsonResponse
    {
        $data = $request->validate(['size' => ['required', 'integer', 'min:0']]);

        return $this->attempt(fn () => ['upload' => $this->drive->startUpload(ResolveOrganization::current($request), (int) $data['size'])]);
    }

    /**
     * One part of an upload, as the request's body.
     */
    public function uploadPart(Request $request, int $part): JsonResponse
    {
        return $this->attempt(function () use ($request, $part) {
            $this->drive->receivePart((string) $request->header('X-Onedrop-Upload'), $part, $request->getContent());

            return ['ok' => true];
        });
    }

    /**
     * Finish an upload as a file in a folder: a file there by that name gets the new version.
     */
    public function storeFile(Request $request, string $space): JsonResponse
    {
        $place = $this->drive->pick($this->spaces($request), $space) ?? abort(404);
        $data = $request->validate(['upload' => ['required', 'string'], 'name' => ['required', 'string', 'max:255'], 'parent_id' => ['nullable', 'integer']]);
        $parent = $this->folderIn($place, $data['parent_id'] ?? null);

        return $this->attempt(fn () => $this->item($this->drive->finishUpload($data['upload'], $place, $parent, $data['name'], $request->user())));
    }

    /**
     * Rename an item, or move it to a folder (in another place too).
     */
    public function update(Request $request, DriveItem $item): RedirectResponse
    {
        $spaces = $this->spaces($request);
        $this->authorizeItem($spaces, $item);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'space' => ['sometimes', 'string'], 'parent_id' => ['sometimes', 'nullable', 'integer']]);
        $place = array_key_exists('space', $data) ? ($this->drive->pick($spaces, $data['space']) ?? abort(404)) : $item->driveSpace();
        $parent = array_key_exists('parent_id', $data) || array_key_exists('space', $data) ? $this->folderIn($place, $data['parent_id'] ?? null) : $item->parent;

        $this->orFail(fn () => $this->drive->move($item, $place, $parent, $data['name'] ?? $item->name, $request->user()));

        return back();
    }

    /**
     * Put an item in Trash (DRIVE-002).
     */
    public function destroy(Request $request, DriveItem $item): RedirectResponse
    {
        $this->authorizeItem($this->spaces($request), $item);
        $this->drive->trash($item, $request->user());

        return back();
    }

    /**
     * Take an item out of Trash.
     */
    public function restore(Request $request, DriveItem $item): RedirectResponse
    {
        $this->authorizeItem($this->spaces($request), $item);
        abort_if($item->trashed_at === null, 404);
        $this->drive->restore($item, $request->user());

        return back();
    }

    /**
     * Delete an item in Trash for good.
     */
    public function purge(Request $request, DriveItem $item): RedirectResponse
    {
        $this->authorizeItem($this->spaces($request), $item);
        abort_if($item->trashed_at === null, 404);
        $this->drive->purge($item);

        return back();
    }

    /**
     * Delete everything in a place's Trash for good.
     */
    public function emptyTrash(Request $request, string $space): RedirectResponse
    {
        $place = $this->drive->pick($this->spaces($request), $space) ?? abort(404);
        $this->drive->trashed($place)->each(fn (DriveItem $item) => $this->drive->purge($item));

        return back();
    }

    /**
     * Download a file, or a folder as a zip.
     */
    public function download(Request $request, DriveItem $item): HttpResponse
    {
        $this->authorizeItem($this->spaces($request), $item, visible: true);

        return $item->is_folder ? $this->drive->zip($item) : $this->drive->contents($item, download: true);
    }

    /**
     * A file shown in the page (images, video, audio, PDFs).
     */
    public function view(Request $request, DriveItem $item): HttpResponse
    {
        $this->authorizeItem($this->spaces($request), $item, visible: true);

        return $this->drive->contents($item, download: false);
    }

    /**
     * A text file for the editor, or 415 when it isn't text or is too big.
     */
    public function text(Request $request, DriveItem $item): JsonResponse
    {
        $this->authorizeItem($this->spaces($request), $item, visible: true);
        $text = $this->drive->readText($item);

        return $text === null
            ? response()->json(['message' => __("This file can't be opened as text.")], 415)
            : response()->json(['text' => $text, 'revision' => $item->revision]);
    }

    /**
     * Save a text file from the editor, unless it changed since it was opened.
     */
    public function saveText(Request $request, DriveItem $item): JsonResponse
    {
        $this->authorizeItem($this->spaces($request), $item, visible: true);
        $data = $request->validate(['text' => ['present', 'nullable', 'string', 'max:'.Drive::TEXT_BYTES], 'revision' => ['required', 'integer']]);

        return $this->attempt(fn () => $this->item($this->drive->writeText($item, (string) ($data['text'] ?? ''), $request->user(), (int) $data['revision'])));
    }

    /**
     * The places the user may open in the address's organization.
     *
     * @return Collection<int, DriveSpace>
     */
    protected function spaces(Request $request): Collection
    {
        return $this->drive->spacesFor($request->user(), ResolveOrganization::current($request));
    }

    /**
     * A 404 unless the item is in one of the user's places (and, with $visible, not in Trash).
     *
     * @param  Collection<int, DriveSpace>  $spaces
     */
    protected function authorizeItem(Collection $spaces, DriveItem $item, bool $visible = false): void
    {
        abort_unless($this->drive->spaceOf($item, $spaces) !== null && (! $visible || $this->drive->visible($item)), 404);
    }

    /**
     * A folder in the place, or the top for null.
     */
    protected function folderIn(DriveSpace $place, ?int $id): ?DriveItem
    {
        if ($id === null) {
            return null;
        }

        $folder = DriveItem::query()->in($place)->whereKey($id)->first();

        abort_unless($folder && $folder->is_folder && $this->drive->visible($folder), 404);

        return $folder;
    }

    /**
     * What the page shows of an item.
     *
     * @return array<string, mixed>
     */
    protected function item(DriveItem $item, bool $trash = false): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'folder' => $item->is_folder,
            'size' => $item->size,
            'mime_type' => $item->mime_type,
            'revision' => $item->revision,
            'updated_at' => $item->updated_at?->toIso8601String(),
            'updated_by' => $item->updater?->name,
            ...($trash ? [
                'trashed_at' => $item->trashed_at?->toIso8601String(),
                'trashed_by' => $item->trasher?->name,
                'location' => $item->parent ? $this->drive->path($item->parent) : null,
            ] : []),
        ];
    }

    /**
     * Run a change for a JSON request, answering what Drive refused with its reason.
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

    /**
     * Run a change for a page's form, answering what Drive refused as a validation error.
     */
    protected function orFail(callable $change): void
    {
        try {
            $change();
        } catch (DriveException $e) {
            throw ValidationException::withMessages(['drive' => $e->getMessage()]);
        }
    }
}
