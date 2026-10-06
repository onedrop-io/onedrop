<?php

namespace App\Sandbox\Drive;

use App\Enums\DriveSpaceKind;
use App\Events\DriveChanged;
use App\Models\DriveItem;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;
use ZipArchive;

/**
 * Drive (DRIVE-001..004): files and folders kept by the platform, in a person's My Drive, their organization's drive
 * and their groups' drives, the same from the web, their computer and projects. Contents live on Drive's disk as one
 * blob per file version; every change takes the next revision, which sandboxes follow to stay in step (DRIVE-003).
 */
class Drive
{
    /** Uploads arrive in parts of this size (the last may be smaller), through the app (S3 multipart on S3). */
    public const PART_BYTES = 8 * 1024 * 1024;

    /** How long an upload may take, from its first part to its last. */
    public const UPLOAD_HOURS = 24;

    /** The largest file the editor opens (DRIVE-001). */
    public const TEXT_BYTES = 2 * 1024 * 1024;

    /** What a person's own place is called. */
    public const MY_DRIVE = 'My Drive';

    /**
     * The disk Drive keeps contents on: its own if set, else the app's default (S3 on Laravel Cloud).
     */
    public function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk($this->diskName());
    }

    protected function diskName(): string
    {
        return (string) (config('drive.disk') ?: config('filesystems.default'));
    }

    /**
     * The places the user may open in the organization: My Drive, the organization's, and their groups' (every group's
     * for its owners and admins, who manage every group).
     *
     * @return Collection<int, DriveSpace>
     */
    public function spacesFor(User $user, Organization $organization): Collection
    {
        if (! $user->belongsToOrganization($organization)) {
            return collect();
        }

        $groups = $organization->isManagedBy($user)
            ? $organization->groups()->orderBy('name')->get()
            : $user->groups()->where('groups.organization_id', $organization->id)->orderBy('name')->get();

        return collect([
            new DriveSpace(DriveSpaceKind::Personal, $organization->id, userId: $user->id, name: self::MY_DRIVE),
            new DriveSpace(DriveSpaceKind::Organization, $organization->id, name: $organization->name),
        ])->concat($groups->map(fn (Group $group) => new DriveSpace(DriveSpaceKind::Group, $organization->id, groupId: $group->id, name: $group->name)));
    }

    /**
     * The places a project's sandbox syncs: the organization's drive and its owner's groups' drives, plus the owner's
     * My Drive for their computer (DRIVE-003, DRIVE-004). Nothing once the owner has left the organization.
     *
     * @return Collection<int, DriveSpace>
     */
    public function spacesForProject(Project $project): Collection
    {
        $owner = $project->user;
        $organization = $project->organization;

        if ($owner === null || ! $owner->belongsToOrganization($organization)) {
            return collect();
        }

        $groups = $owner->groups()->where('groups.organization_id', $organization->id)->orderBy('name')->get();

        return collect($project->isComputer() ? [new DriveSpace(DriveSpaceKind::Personal, $organization->id, userId: $owner->id, name: self::MY_DRIVE)] : [])
            ->push(new DriveSpace(DriveSpaceKind::Organization, $organization->id, name: $organization->name))
            ->concat($groups->map(fn (Group $group) => new DriveSpace(DriveSpaceKind::Group, $organization->id, groupId: $group->id, name: $group->name)));
    }

    /**
     * One of the places in $spaces by its key, or null.
     *
     * @param  Collection<int, DriveSpace>  $spaces
     */
    public function pick(Collection $spaces, string $key): ?DriveSpace
    {
        return $spaces->first(fn (DriveSpace $space) => $space->key() === $key);
    }

    /**
     * The place an item is in, if it's one of $spaces (with its name).
     *
     * @param  Collection<int, DriveSpace>  $spaces
     */
    public function spaceOf(DriveItem $item, Collection $spaces): ?DriveSpace
    {
        return $spaces->first(fn (DriveSpace $space) => $space->is($item->driveSpace()));
    }

    /**
     * What's in a folder (or at the top), not counting Trash: folders first, then by name.
     *
     * @return Collection<int, DriveItem>
     */
    public function children(DriveSpace $space, ?DriveItem $parent): Collection
    {
        return DriveItem::query()->in($space)->live()
            ->where('parent_id', $parent?->id)
            ->with('updater:id,name')
            ->orderByDesc('is_folder')->orderBy('name')
            ->get();
    }

    /**
     * What's in the place's Trash, latest first (DRIVE-002). Items inside a trashed folder go with it, so only the
     * folder is listed.
     *
     * @return Collection<int, DriveItem>
     */
    public function trashed(DriveSpace $space): Collection
    {
        return DriveItem::query()->in($space)->whereNotNull('trashed_at')
            ->with(['trasher:id,name', 'parent'])
            ->latest('trashed_at')->latest('id')
            ->get()
            ->reject(fn (DriveItem $item) => $this->insideTrash($item))
            ->values();
    }

    /**
     * The folders an item is in, from the top down.
     *
     * @return list<DriveItem>
     */
    public function ancestors(DriveItem $item): array
    {
        $ancestors = [];
        $seen = [];

        for ($parent = $item->parent; $parent && ! isset($seen[$parent->id]); $parent = $parent->parent) {
            $seen[$parent->id] = true;
            array_unshift($ancestors, $parent);
        }

        return $ancestors;
    }

    /**
     * Whether it can be seen: not in Trash, and not inside a folder that is.
     */
    public function visible(DriveItem $item): bool
    {
        return $item->trashed_at === null && ! $this->insideTrash($item);
    }

    /**
     * Whether a folder it's inside is in Trash.
     */
    protected function insideTrash(DriveItem $item): bool
    {
        return collect($this->ancestors($item))->contains(fn (DriveItem $ancestor) => $ancestor->trashed_at !== null);
    }

    /**
     * Its path from the top of its place ("Reports/2026/q3.pdf").
     */
    public function path(DriveItem $item): string
    {
        return collect($this->ancestors($item))->push($item)->pluck('name')->implode('/');
    }

    /**
     * The bytes the organization's files take, Trash included.
     */
    public function usage(Organization $organization): int
    {
        return (int) DriveItem::query()->where('organization_id', $organization->id)->where('is_folder', false)->sum('size');
    }

    /**
     * How many bytes an organization may keep, or null for no limit.
     */
    public function quota(): ?int
    {
        $gigabytes = config('drive.quota_gb');

        return $gigabytes === null ? null : (int) round((float) $gigabytes * 1024 ** 3);
    }

    /**
     * Make a folder. With $reuse, a folder already there by that name is returned instead (a sandbox making a folder
     * Drive already has); otherwise a taken name gets a number.
     */
    public function createFolder(DriveSpace $space, ?DriveItem $parent, string $name, ?User $actor, bool $reuse = false): DriveItem
    {
        $name = $this->cleanName($name);
        $this->ensureFolderIn($space, $parent);

        $this->changed($space);

        return DB::transaction(function () use ($space, $parent, $name, $actor, $reuse) {
            $existing = $this->sibling($space, $parent, $name);

            if ($reuse && $existing?->is_folder) {
                return $existing;
            }

            return DriveItem::create([
                ...$this->placeColumns($space),
                'parent_id' => $parent?->id,
                'name' => $existing ? $this->freeName($space, $parent, $name) : $name,
                'is_folder' => true,
                'revision' => $this->nextRevision(),
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);
        });
    }

    /**
     * Start an upload of $size bytes into the organization's Drive, if it has room: a token naming where its parts go,
     * for receivePart() and finishUpload().
     *
     * @throws DriveException
     */
    public function startUpload(Organization $organization, int $size): string
    {
        $max = (int) config('drive.max_file_mb') * 1024 * 1024;

        if ($size < 0 || $size > $max) {
            throw new DriveException(__('Files can be up to :size.', ['size' => Number::fileSize($max)]));
        }

        $this->ensureRoom($organization, $size);

        $blob = "drive/{$organization->id}/".Str::ulid()->toBase32();
        $multipart = null;

        if ($this->parts($size) > 1 && $this->s3()) {
            $multipart = $this->s3()->createMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $this->disk()->path($blob)])['UploadId'];
        }

        return Crypt::encryptString(json_encode([
            'organization' => $organization->id,
            'blob' => $blob,
            'size' => $size,
            'multipart' => $multipart,
            'expires' => now()->addHours(self::UPLOAD_HOURS)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Store one part of an upload: part $index (from 0) of PART_BYTES, the last one what's left.
     *
     * @throws DriveException
     */
    public function receivePart(string $token, int $index, string $body): void
    {
        $upload = $this->upload($token);
        $parts = $this->parts($upload['size']);
        $expected = $index === $parts - 1 ? $upload['size'] - self::PART_BYTES * ($parts - 1) : self::PART_BYTES;

        if ($index < 0 || $index >= $parts || strlen($body) !== $expected) {
            throw new DriveException(__('That part of the upload is the wrong size.'));
        }

        if ($upload['multipart'] !== null) {
            $this->s3()?->uploadPart([
                'Bucket' => $this->bucket(),
                'Key' => $this->disk()->path($upload['blob']),
                'UploadId' => $upload['multipart'],
                'PartNumber' => $index + 1,
                'Body' => $body,
            ]);

            return;
        }

        if ($parts === 1 || $this->s3()) {
            $this->disk()->put($upload['blob'], $body);

            return;
        }

        // A local disk: each part in its place in one file, so parts may arrive in any order.
        $path = $this->disk()->path($upload['blob']);
        @mkdir(dirname($path), 0755, true);
        $file = fopen($path, 'c');
        fseek($file, $index * self::PART_BYTES);
        fwrite($file, $body);
        fclose($file);
    }

    /**
     * Finish an upload as the file $name in a folder (or at the top): a new file, or a new version of $replacing. When
     * the name is taken by another file, the upload replaces it unless $keepBoth; a version based on an older revision
     * than the file's (changed somewhere else meanwhile) is kept beside it as a conflicted copy (DRIVE-003).
     *
     * @throws DriveException
     */
    public function finishUpload(string $token, DriveSpace $space, ?DriveItem $parent, string $name, ?User $actor, ?DriveItem $replacing = null, ?int $baseRevision = null, ?string $sha256 = null, bool $keepBoth = false): DriveItem
    {
        $upload = $this->upload($token);
        $name = $this->cleanName($name);

        if ($upload['organization'] !== $space->organizationId) {
            throw new DriveException(__('That upload is for another organization.'));
        }

        $this->ensureFolderIn($space, $parent);
        $this->completeUpload($upload);

        $sha256 = $sha256 !== null && preg_match('/^[a-f0-9]{64}$/', $sha256) ? $sha256 : null;

        $old = null;

        $item = DB::transaction(function () use ($upload, $space, $parent, $name, $actor, $replacing, $baseRevision, $sha256, $keepBoth, &$old) {
            $target = $replacing ?? $this->sibling($space, $parent, $name);

            if ($target?->is_folder || ($target && $keepBoth) || ($target && $target->trashed_at !== null)) {
                $target = null;
                $name = $this->freeName($space, $parent, $name);
            } elseif ($target && $baseRevision !== null && $target->revision !== $baseRevision) {
                // Changed somewhere else since this version was based on it: keep both.
                $target = null;
                $name = $this->freeName($space, $parent, $this->conflictName($name));
            }

            $this->ensureRoom(Organization::query()->findOrFail($space->organizationId), $upload['size'] - ($target?->size ?? 0));

            $columns = [
                'size' => $upload['size'],
                'mime_type' => $this->mimeType($name),
                'sha256' => $sha256,
                'blob' => $upload['blob'],
                'revision' => $this->nextRevision(),
                'updated_by' => $actor?->id,
            ];

            if ($target) {
                $old = $target->blob;
                $target->update($columns);

                return $target;
            }

            return DriveItem::create([
                ...$this->placeColumns($space),
                ...$columns,
                'is_folder' => false,
                'parent_id' => $parent?->id,
                'name' => $name,
                'created_by' => $actor?->id,
            ]);
        });

        if ($old !== null && $old !== $item->blob) {
            $this->disk()->delete($old);
        }

        $this->changed($space);

        return $item;
    }

    /**
     * Save new text for a file from the editor (DRIVE-001).
     *
     * @throws DriveException
     */
    public function writeText(DriveItem $item, string $contents, ?User $actor, ?int $baseRevision = null): DriveItem
    {
        if ($item->is_folder) {
            throw new DriveException(__("A folder can't be edited."));
        }

        if ($baseRevision !== null && $item->revision !== $baseRevision) {
            throw new DriveException(__('This file was changed somewhere else since you opened it. Open it again to see the changes.'));
        }

        $organization = $item->organization;
        $token = $this->startUpload($organization, strlen($contents));

        foreach (str_split($contents, self::PART_BYTES) ?: [''] as $index => $part) {
            if (strlen($contents) > 0) {
                $this->receivePart($token, $index, $part);
            }
        }

        return $this->finishUpload($token, $item->driveSpace(), $item->parent, $item->name, $actor, replacing: $item, sha256: hash('sha256', $contents));
    }

    /**
     * Rename and/or move an item, to another place too. A name taken in the new folder gets a number. An item based on
     * an older revision than its own (moved somewhere else meanwhile) is left where it is.
     *
     * @throws DriveException
     */
    public function move(DriveItem $item, DriveSpace $space, ?DriveItem $parent, string $name, ?User $actor, ?int $baseRevision = null): DriveItem
    {
        $name = $this->cleanName($name);
        $this->ensureFolderIn($space, $parent);

        if ($baseRevision !== null && $item->revision !== $baseRevision) {
            return $item;
        }

        // Not into itself or one of its own folders.
        for ($folder = $parent; $folder; $folder = $folder->parent) {
            if ($folder->is($item)) {
                throw new DriveException(__("A folder can't go inside itself."));
            }
        }

        $this->changed($item->driveSpace(), $space);

        return DB::transaction(function () use ($item, $space, $parent, $name, $actor) {
            $sameFolder = $item->driveSpace()->is($space) && $item->parent_id === $parent?->id;
            $taken = $this->sibling($space, $parent, $name);

            if ($sameFolder && $item->name === $name) {
                return $item;
            }

            $item->update([
                ...$this->placeColumns($space),
                'parent_id' => $parent?->id,
                'name' => $taken && ! $taken->is($item) ? $this->freeName($space, $parent, $name) : $name,
                'revision' => $this->nextRevision(),
                'updated_by' => $actor?->id,
            ]);

            // Everything inside a folder moved to another place goes there too.
            if ($item->is_folder && ! $sameFolder) {
                foreach ($this->descendants($item) as $descendant) {
                    $descendant->update([...$this->placeColumns($space), 'revision' => $this->nextRevision()]);
                }
            }

            return $item;
        });
    }

    /**
     * Put an item in Trash (DRIVE-002): what's inside a folder goes with it.
     */
    public function trash(DriveItem $item, ?User $actor): void
    {
        if ($item->trashed_at !== null) {
            return;
        }

        $item->update(['trashed_at' => now(), 'trashed_by' => $actor?->id, 'revision' => $this->nextRevision()]);
        $this->changed($item->driveSpace());
    }

    /**
     * Take an item out of Trash, back to its folder (or the top, when its folder is in Trash or gone), beside anything
     * that took its name meanwhile.
     */
    public function restore(DriveItem $item, ?User $actor): DriveItem
    {
        $this->changed($item->driveSpace());

        return DB::transaction(function () use ($item, $actor) {
            $parent = $item->parent && $this->visible($item->parent) ? $item->parent : null;
            $space = $item->driveSpace();

            $item->update([
                'parent_id' => $parent?->id,
                'name' => $this->sibling($space, $parent, $item->name) ? $this->freeName($space, $parent, $item->name) : $item->name,
                'trashed_at' => null,
                'trashed_by' => null,
                'updated_by' => $actor?->id,
                'revision' => $this->nextRevision(),
            ]);

            // Sandboxes forgot what was inside it when it went to Trash; this tells them again.
            foreach ($this->descendants($item) as $descendant) {
                $descendant->update(['revision' => $this->nextRevision()]);
            }

            return $item;
        });
    }

    /**
     * Delete an item for good, with everything inside it and their contents.
     */
    public function purge(DriveItem $item): void
    {
        $blobs = collect($this->descendants($item))->push($item)->pluck('blob')->filter()->all();

        $item->delete();
        $this->changed($item->driveSpace());

        if ($blobs !== []) {
            $this->disk()->delete($blobs);
        }
    }

    /**
     * Delete for good what's been in Trash longer than Drive keeps it (DRIVE-002). Returns how many items went.
     */
    public function purgeExpired(): int
    {
        $count = 0;

        DriveItem::query()->where('trashed_at', '<', now()->subDays((int) config('drive.trash_days')))
            ->orderBy('id')
            ->each(function (DriveItem $item) use (&$count) {
                // Gone already, with a folder it was in.
                if (DriveItem::query()->whereKey($item->id)->exists()) {
                    $this->purge($item);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Delete contents no item points at any more (an upload never finished, a file replaced while a sandbox read it),
     * once they're a day old.
     */
    public function collectGarbage(): int
    {
        $disk = $this->disk();
        $count = 0;

        foreach ($disk->directories('drive') as $directory) {
            $blobs = collect($disk->files($directory));
            $kept = DriveItem::query()->whereIn('blob', $blobs)->pluck('blob')->flip();

            foreach ($blobs as $blob) {
                if (! $kept->has($blob) && $disk->lastModified($blob) < now()->subDay()->getTimestamp()) {
                    $disk->delete($blob);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Everything that changed in $spaces after revision $after, oldest first, for a sandbox to catch up on (DRIVE-003):
     * at most $limit items, and the revision to ask after next time.
     *
     * @param  Collection<int, DriveSpace>  $spaces
     * @return array{items: Collection<int, DriveItem>, cursor: int, more: bool}
     */
    public function changes(Collection $spaces, int $after, int $limit = 500): array
    {
        $latest = $this->currentRevision();

        if ($spaces->isEmpty()) {
            return ['items' => collect(), 'cursor' => $latest, 'more' => false];
        }

        $items = DriveItem::query()
            ->where(fn ($query) => $spaces->each(fn (DriveSpace $space) => $query->orWhere(fn ($place) => $place->in($space))))
            ->where('revision', '>', $after)
            ->where('revision', '<=', $latest)
            ->orderBy('revision')
            ->limit($limit + 1)
            ->get();

        $more = $items->count() > $limit;
        $items = $items->take($limit);

        return ['items' => $items, 'cursor' => $more ? (int) $items->last()->revision : $latest, 'more' => $more];
    }

    /**
     * Everything not in Trash in one place, by id after $afterId, for a sandbox that's starting over or just got the place.
     *
     * @return Collection<int, DriveItem>
     */
    public function listing(DriveSpace $space, int $afterId = 0, int $limit = 1000): Collection
    {
        return DriveItem::query()->in($space)->live()->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get();
    }

    /**
     * The latest revision any change took.
     */
    public function currentRevision(): int
    {
        return (int) DB::table('drive_revisions')->max('id');
    }

    /**
     * A file's contents: a link to the disk on S3 (so large files don't pass through the app), else from the app.
     * $download saves it under its name; otherwise browsers show what they can.
     */
    public function contents(DriveItem $item, bool $download): Response
    {
        if ($item->is_folder || $item->blob === null) {
            abort(404);
        }

        $disposition = ($download ? 'attachment' : 'inline').'; filename="'.Str::ascii(str_replace('"', '', $item->name)).'"; filename*=UTF-8\'\''.rawurlencode($item->name);
        $type = $item->mime_type ?: 'application/octet-stream';

        if ($this->s3()) {
            return redirect()->away($this->disk()->temporaryUrl($item->blob, now()->addMinutes(30), [
                'ResponseContentDisposition' => $disposition,
                'ResponseContentType' => $type,
            ]));
        }

        $stream = $this->disk()->readStream($item->blob) ?? abort(404);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) $item->size,
            'Content-Disposition' => $disposition,
            // Shown inline from the app's own address: never let a file run as a page of it.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A small file's text for the editor, or null when it's too big or not text.
     */
    public function readText(DriveItem $item): ?string
    {
        if ($item->is_folder || $item->blob === null || $item->size > self::TEXT_BYTES) {
            return null;
        }

        $text = $this->disk()->get($item->blob) ?? '';

        return mb_check_encoding($text, 'UTF-8') && ! str_contains($text, "\0") ? $text : null;
    }

    /**
     * A folder and what's in it as a zip, built on the app's disk and sent as it's read.
     */
    public function zip(DriveItem $folder): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'drive-zip-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $this->addToZip($zip, $folder, $folder->name);
        $zip->close();

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, "{$folder->name}.zip", ['Content-Type' => 'application/zip']);
    }

    protected function addToZip(ZipArchive $zip, DriveItem $item, string $path): void
    {
        if (! $item->is_folder) {
            $zip->addFromString($path, $this->disk()->get((string) $item->blob) ?? '');

            return;
        }

        $zip->addEmptyDir($path);

        foreach ($item->children()->live()->get() as $child) {
            $this->addToZip($zip, $child, "{$path}/{$child->name}");
        }
    }

    /**
     * Everything inside a folder, however deep.
     *
     * @return list<DriveItem>
     */
    public function descendants(DriveItem $folder): array
    {
        $found = [];
        $parents = [$folder->id];

        while ($parents !== []) {
            $children = DriveItem::query()->whereIn('parent_id', $parents)->get();
            $found = [...$found, ...$children->all()];
            $parents = $children->where('is_folder', true)->pluck('id')->all();
        }

        return $found;
    }

    /**
     * A name Drive accepts: one path segment, at most 255 characters.
     *
     * @throws DriveException
     */
    public function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || mb_strlen($name) > 255) {
            throw new DriveException(__('Names can’t be empty, “.” or “..”, contain “/”, or be longer than 255 characters.'));
        }

        return $name;
    }

    /**
     * A name that isn't taken in the folder: "Report (2).pdf", "Report (3).pdf"…
     */
    public function freeName(DriveSpace $space, ?DriveItem $parent, string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension !== '' && $extension !== $name ? Str::beforeLast($name, ".{$extension}") : $name;
        $suffix = $extension !== '' && $extension !== $name ? ".{$extension}" : '';

        for ($n = 2; $this->sibling($space, $parent, $name) !== null; $n++) {
            $name = "{$base} ({$n}){$suffix}";
        }

        return $name;
    }

    /**
     * The name for the second of two changes made at once: "Report (conflict 2026-10-06 15.04).pdf".
     */
    protected function conflictName(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stamp = now()->format('Y-m-d H.i');

        return $extension !== '' && $extension !== $name
            ? Str::beforeLast($name, ".{$extension}")." (conflict {$stamp}).{$extension}"
            : "{$name} (conflict {$stamp})";
    }

    /**
     * What's called $name in the folder, not counting Trash.
     */
    protected function sibling(DriveSpace $space, ?DriveItem $parent, string $name): ?DriveItem
    {
        return DriveItem::query()->in($space)->live()->where('parent_id', $parent?->id)->where('name', $name)->first();
    }

    /**
     * @throws DriveException
     */
    protected function ensureFolderIn(DriveSpace $space, ?DriveItem $parent): void
    {
        if ($parent !== null && (! $parent->is_folder || ! $parent->driveSpace()->is($space) || ! $this->visible($parent))) {
            throw new DriveException(__('That folder no longer exists.'));
        }
    }

    /**
     * @throws DriveException
     */
    protected function ensureRoom(Organization $organization, int $bytes): void
    {
        $quota = $this->quota();

        if ($quota !== null && $bytes > 0 && $this->usage($organization) + $bytes > $quota) {
            throw new DriveException(__('Drive is full: :organization may keep up to :quota. Delete files (and empty Trash) to make room.', [
                'organization' => $organization->name,
                'quota' => Number::fileSize($quota),
            ]));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function placeColumns(DriveSpace $space): array
    {
        return [
            'organization_id' => $space->organizationId,
            'space' => $space->kind,
            'user_id' => $space->userId,
            'group_id' => $space->groupId,
        ];
    }

    /**
     * Tell open Drive pages on these places to reload (DRIVE-001).
     */
    protected function changed(DriveSpace ...$spaces): void
    {
        foreach ($spaces as $space) {
            DriveChanged::signal($space);
        }
    }

    /**
     * The next revision: every change takes its own, so sandboxes can ask for what changed since the last they saw.
     */
    public function nextRevision(): int
    {
        return (int) DB::table('drive_revisions')->insertGetId([]);
    }

    protected function parts(int $size): int
    {
        return max(1, (int) ceil($size / self::PART_BYTES));
    }

    /**
     * An upload's details from its token.
     *
     * @return array{organization: int, blob: string, size: int, multipart: string|null, expires: int}
     *
     * @throws DriveException
     */
    protected function upload(string $token): array
    {
        try {
            $upload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new DriveException(__('That upload isn’t valid. Try again.'));
        }

        if (($upload['expires'] ?? 0) < now()->getTimestamp()) {
            throw new DriveException(__('That upload took too long. Try again.'));
        }

        return $upload;
    }

    /**
     * Put a multipart upload's parts together, and check every byte arrived.
     *
     * @param  array{organization: int, blob: string, size: int, multipart: string|null, expires: int}  $upload
     *
     * @throws DriveException
     */
    protected function completeUpload(array $upload): void
    {
        if ($upload['size'] === 0 && ! $this->disk()->exists($upload['blob'])) {
            $this->disk()->put($upload['blob'], '');
        }

        if ($upload['multipart'] !== null && $this->s3()) {
            $key = $this->disk()->path($upload['blob']);
            $parts = $this->s3()->listParts(['Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $upload['multipart']])['Parts'] ?? [];

            if (count($parts) !== $this->parts($upload['size'])) {
                throw new DriveException(__('Part of the upload is missing. Try again.'));
            }

            $this->s3()->completeMultipartUpload([
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $upload['multipart'],
                'MultipartUpload' => ['Parts' => collect($parts)->map(fn (array $part) => ['PartNumber' => $part['PartNumber'], 'ETag' => $part['ETag']])->all()],
            ]);
        }

        if (! $this->disk()->exists($upload['blob']) || $this->disk()->size($upload['blob']) !== $upload['size']) {
            throw new DriveException(__('Part of the upload is missing. Try again.'));
        }
    }

    protected function mimeType(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return ($extension !== '' ? (MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null) : null) ?? 'application/octet-stream';
    }

    /**
     * The S3 client when Drive's disk is S3, for multipart uploads.
     */
    protected function s3(): ?S3Client
    {
        $disk = $this->disk();

        return $disk instanceof AwsS3V3Adapter ? $disk->getClient() : null;
    }

    protected function bucket(): string
    {
        return (string) config("filesystems.disks.{$this->diskName()}.bucket");
    }
}
