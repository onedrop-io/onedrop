<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * Read-only access to a sandbox's /workspace through short provider exec calls.
 */
class WorkspaceFiles
{
    public const ROOT = '/workspace';

    /** Folders listed but never expanded. */
    public const COLLAPSED = ['node_modules', '.git', 'vendor', '.cache'];

    public const MAX_ENTRIES = 5000;

    public const MAX_BYTES = 200_000;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Every file and folder, relative to the workspace, folders first then by name.
     *
     * @return list<array{path: string, type: 'file'|'dir'}>
     *
     * @throws SandboxException
     */
    public function list(Sandbox $sandbox): array
    {
        $prune = [];
        foreach (self::COLLAPSED as $name) {
            if ($prune !== []) {
                $prune[] = '-o';
            }

            array_push($prune, '-name', $name);
        }

        $result = $this->provider->exec($sandbox->external_id, [
            'find', self::ROOT, '-mindepth', '1',
            '(', ...$prune, ')', '-prune', '-printf', '%y %P\n',
            '-o', '-printf', '%y %P\n',
        ]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't list the project's files.");
        }

        $entries = [];

        foreach (explode("\n", trim($result->output)) as $line) {
            if (strlen($line) < 3 || count($entries) >= self::MAX_ENTRIES) {
                continue;
            }

            $entries[] = ['path' => substr($line, 2), 'type' => $line[0] === 'd' ? 'dir' : 'file'];
        }

        usort($entries, fn (array $a, array $b) => strnatcasecmp($a['path'], $b['path']));

        return $entries;
    }

    /**
     * A file's text, or a reason it can't be shown.
     *
     * @return array{path: string, content: string|null, notice: string|null}
     *
     * @throws SandboxException
     */
    public function read(Sandbox $sandbox, string $path): array
    {
        $result = $this->provider->exec($sandbox->external_id, [
            'head', '--bytes', (string) (self::MAX_BYTES + 1), '--', self::ROOT.'/'.$path,
        ]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't open {$path}.");
        }

        $content = $result->output;

        $notice = match (true) {
            str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8') => "This file isn't text, so it can't be shown here.",
            strlen($content) > self::MAX_BYTES => 'This file is too large to show here.',
            default => null,
        };

        return ['path' => $path, 'content' => $notice ? null : $content, 'notice' => $notice];
    }

    /**
     * Whether a user-supplied path stays inside the workspace.
     */
    public static function isSafePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! in_array('..', explode('/', $path), true);
    }
}
