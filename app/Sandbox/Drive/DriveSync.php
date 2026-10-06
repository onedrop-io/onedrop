<?php

namespace App\Sandbox\Drive;

use App\Models\Project;
use App\Models\Sandbox;
use Illuminate\Support\Collection;

/**
 * How a sandbox's Drive daemon (docker/sandbox/drive.mjs) reaches Drive (DRIVE-003, DRIVE-004): its address, and a
 * token derived from the app key and the sandbox, so nothing needs storing and a sandbox can only speak for itself.
 */
class DriveSync
{
    /** Where a computer keeps its drives: in its Home (CMP-001). */
    public const COMPUTER_DIR = '/workspace/Drive';

    /** Where a project's sandbox keeps them, outside its files; the image links /drive here (DRIVE-004). */
    public const PROJECT_DIR = '/home/sandbox/drive';

    public function __construct(protected Drive $drive) {}

    /**
     * The sandbox's token for the sync API.
     */
    public static function token(Sandbox $sandbox): string
    {
        return hash_hmac('sha256', "drive-sync:{$sandbox->id}", (string) config('app.key'));
    }

    /**
     * Whether a request's token is the sandbox's.
     */
    public static function accepts(Sandbox $sandbox, ?string $token): bool
    {
        return $token !== null && $token !== '' && hash_equals(self::token($sandbox), $token);
    }

    /**
     * What the daemon needs, as the sandbox's environment.
     *
     * @return array<string, string>
     */
    public static function environment(Sandbox $sandbox, Project $project): array
    {
        return [
            'ONEDROP_DRIVE_URL' => rtrim((string) config('sandbox.callback_url'), '/').route('drive-sync.state', $sandbox, absolute: false),
            'ONEDROP_DRIVE_TOKEN' => self::token($sandbox),
            'ONEDROP_DRIVE_DIR' => $project->isComputer() ? self::COMPUTER_DIR : self::PROJECT_DIR,
        ];
    }

    /**
     * The places the sandbox syncs, each with the folder it goes in under the daemon's directory: "My Drive", the
     * organization's name, and "Groups/<name>".
     *
     * @return Collection<int, array{key: string, dir: string, space: DriveSpace}>
     */
    public function places(Sandbox $sandbox): Collection
    {
        $project = $sandbox->project;

        if ($project === null) {
            return collect();
        }

        return $this->drive->spacesForProject($project)->map(fn (DriveSpace $space) => [
            'key' => $space->key(),
            'dir' => match ($space->kind->value) {
                'group' => 'Groups/'.self::folderName($space->name),
                default => self::folderName($space->name),
            },
            'space' => $space,
        ])->values();
    }

    /**
     * A place's name as a folder name.
     */
    public static function folderName(string $name): string
    {
        $name = trim(str_replace(['/', "\0"], '-', $name));

        return in_array($name, ['', '.', '..', 'Groups'], true) ? "{$name} drive" : $name;
    }
}
