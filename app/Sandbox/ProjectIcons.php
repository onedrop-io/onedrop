<?php

namespace App\Sandbox;

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\ChatGptSignInFailed;
use App\Sandbox\Agents\OneOffPrompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The icon shown on a project's sidebar tile. It follows the app's favicon; when the app has none of its own,
 * the project's AI draws one, which is also installed in the app as public/favicon.svg.
 * A copy is kept on OneDrop's disk so the sidebar can show it while the sandbox is paused.
 */
class ProjectIcons
{
    /**
     * The app's default disk, shared by web and queue instances (e.g. object storage on Laravel Cloud).
     */
    public static function disk(): string
    {
        return (string) config('filesystems.default');
    }

    public const MAX_BYTES = 512 * 1024;

    /** Where apps usually keep their favicon; the most recently changed one wins. */
    protected const CANDIDATES = [
        'public/favicon.svg', 'public/favicon.png', 'public/favicon.ico', 'public/icon.svg', 'public/icon.png',
        'app/icon.svg', 'app/icon.png', 'app/favicon.ico', 'static/favicon.svg', 'static/favicon.png',
        'static/favicon.ico', 'favicon.svg', 'favicon.ico',
    ];

    /** SHA-256 of the Laravel starter kit's favicons (its logo), which don't count as the app's own. */
    protected const STARTER_KIT = [
        '242f4f8f93f5fbc6c8aeec500c9bac02dcfe68daba166d484c5cdc986c88d8ee', // public/favicon.svg
        '4606a56e6ef3f5ec39201497f57069d5457ce9cea25227134d0ba378788e9070', // public/favicon.ico
        '4001aa032ff113e1a268a9bbf1ab0fd9949439f9f54a85895956eb323aba977d', // public/apple-touch-icon.png
    ];

    /** The starter kit's logo component, and a mark of the Laravel logo it draws (or of the one OneDrop writes). */
    protected const LOGO_COMPONENT = 'resources/js/components/app-logo-icon.tsx';

    protected const LOGO_MARKS = ['M17.2 5.63325L8.6 0.855469', '@onedrop-app-icon'];

    protected const MIME_TYPES = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    public function __construct(
        protected SandboxProvider $provider,
        protected WorkspaceFiles $files,
        protected OneOffPrompt $ai,
        protected SvgSanitizer $sanitizer,
    ) {}

    /**
     * Mark the project as getting an icon drawn, so the sidebar keeps polling for it.
     */
    public static function markDrawing(Project $project): void
    {
        Cache::put("project-icon-drawing:{$project->id}", true, now()->addMinutes(5));
    }

    /**
     * Which of the given projects are getting an icon drawn right now.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function drawing(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $flags = Cache::many(array_map(fn (int $id) => "project-icon-drawing:{$id}", $ids));

        return array_values(array_filter($ids, fn (int $id) => (bool) ($flags["project-icon-drawing:{$id}"] ?? false)));
    }

    public static function doneDrawing(Project $project): void
    {
        Cache::forget("project-icon-drawing:{$project->id}");
    }

    /**
     * The address the sidebar loads the icon from (changes when the icon does, so browsers refetch it).
     */
    public static function url(Project $project): ?string
    {
        return $project->icon_path && $project->icon_hash
            ? route('projects.icon.show', ['project' => $project, 'v' => substr($project->icon_hash, 0, 12)])
            : null;
    }

    /**
     * After an agent run: pick up the app's favicon, or give the app one if it has none of its own.
     *
     * @throws SandboxException
     * @throws ChatGptSignInFailed
     */
    public function sync(Project $project): void
    {
        $sandbox = $this->runningSandbox($project);
        $found = $this->appIcon($sandbox);

        if ($found !== null) {
            $hash = hash('sha256', $found['bytes']);

            // A copy missing from the disk (e.g. kept on another instance's local disk) is stored again.
            if ($hash !== $project->icon_hash || ! $project->icon_path || ! Storage::disk(self::disk())->exists($project->icon_path)) {
                $this->store($project, $found['bytes'], $found['mime']);
            }

            if (str_starts_with($found['path'], 'public/')) {
                $this->showInLogo($sandbox, '/'.substr($found['path'], strlen('public/')), $hash);
            }

            return;
        }

        if ($project->icon_path && Storage::disk(self::disk())->exists($project->icon_path) && $project->icon_mime === 'image/svg+xml') {
            $this->install($sandbox, (string) Storage::disk(self::disk())->get($project->icon_path));

            return;
        }

        $this->draw($project);
    }

    /**
     * Have the project's AI draw a new icon, then install it in the app and show it in the sidebar.
     *
     * @throws SandboxException
     * @throws ChatGptSignInFailed
     */
    public function draw(Project $project): void
    {
        $sandbox = $this->runningSandbox($project);
        $answer = $this->ai->ask($project, $this->drawingPrompt($project));
        $svg = preg_match('/<svg\b.*<\/svg>/is', $answer, $match) ? $this->sanitizer->clean($match[0]) : null;

        if ($svg === null) {
            throw new SandboxException("The AI's icon wasn't a usable SVG.");
        }

        $this->install($sandbox, $svg);
        $this->store($project, $svg, 'image/svg+xml');
    }

    /**
     * Use an uploaded image as the app's icon. SVGs are cleaned; other images are wrapped in an SVG,
     * so the app always serves its icon from public/favicon.svg.
     *
     * @throws SandboxException
     */
    public function replace(Project $project, string $bytes, string $mime): void
    {
        $sandbox = $this->runningSandbox($project);

        if ($mime === 'image/svg+xml') {
            $svg = $this->sanitizer->clean($bytes) ?? throw new SandboxException(__("That SVG couldn't be used as an icon."));
        } else {
            $size = @getimagesizefromstring($bytes) ?: throw new SandboxException(__("That image couldn't be read."));
            $svg = sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d"><image href="data:%3$s;base64,%4$s" width="%1$d" height="%2$d"/></svg>',
                $size[0], $size[1], $mime, base64_encode($bytes),
            );
        }

        $this->install($sandbox, $svg);
        $this->store($project, $svg, 'image/svg+xml');
    }

    /**
     * Remove the stored copy (when the project is deleted).
     */
    public function delete(Project $project): void
    {
        Storage::disk(self::disk())->deleteDirectory("project-icons/{$project->id}");
    }

    protected function runningSandbox(Project $project): Sandbox
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            throw new SandboxException(__("The project's sandbox isn't running."));
        }

        return $sandbox;
    }

    /**
     * The app's own favicon, if it has one: the usual places first (newest first), then any favicon elsewhere in
     * the app, such as a cloned repository's src/favicon.ico or apps/web/public/favicon.svg (shallowest first,
     * SVG over PNG over ICO). Empty files and the starter kit's logo are skipped.
     *
     * @return array{path: string, bytes: string, mime: string}|null
     */
    protected function appIcon(Sandbox $sandbox): ?array
    {
        $script = <<<'SH'
        cd "$1" || exit 0; shift
        {
            ls -t -- "$@" 2>/dev/null
            find . -maxdepth 4 \( -name node_modules -o -name vendor -o -name .git -o -name dist -o -name build -o -name .next -o -name .nuxt -o -name storage -o -name coverage \) -prune \
                -o -type f \( -iname 'favicon.*' -o -iname 'favicon-*.png' -o -iname 'icon.svg' -o -iname 'icon.png' -o -iname 'apple-touch-icon*.png' \) -print 2>/dev/null \
                | sed 's|^\./||' | head -n 20
        } | awk '!seen[$0]++' | while read -r f; do printf "%s\t" "$f"; head -c MAX_BYTES -- "$f" | base64 | tr -d "\n"; echo; done
        SH;

        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c', str_replace('MAX_BYTES', (string) (self::MAX_BYTES + 1), $script),
            'sh', WorkspaceFiles::ROOT, ...self::CANDIDATES,
        ]);

        $formats = array_flip(array_keys(self::MIME_TYPES));
        $lines = collect(explode("\n", trim($result->output)))
            ->map(fn (string $line) => array_pad(explode("\t", $line, 2), 2, ''))
            ->sortBy(fn (array $file, int $order) => in_array($file[0], self::CANDIDATES, true)
                ? [0, 0, 0, $order]
                : [1, substr_count($file[0], '/'), $formats[strtolower(pathinfo($file[0], PATHINFO_EXTENSION))] ?? count($formats), $order]);

        foreach ($lines as [$path, $encoded]) {
            $bytes = base64_decode($encoded, true);
            $mime = self::MIME_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

            if ($bytes === false || $bytes === '' || $mime === null || strlen($bytes) > self::MAX_BYTES) {
                continue;
            }

            if (! in_array(hash('sha256', $bytes), self::STARTER_KIT, true)) {
                return ['path' => $path, 'bytes' => $bytes, 'mime' => $mime];
            }
        }

        return null;
    }

    /**
     * Write the icon to the app's public/favicon.svg (when it has a public folder), and take out the
     * starter kit's logo so browsers don't pick it instead.
     */
    protected function install(Sandbox $sandbox, string $svg): void
    {
        $root = WorkspaceFiles::ROOT;
        $hasPublic = $this->provider->exec($sandbox->external_id, ['test', '-d', "{$root}/public"])->successful();

        if (! $hasPublic) {
            return;
        }

        $this->files->upload($sandbox, 'public/favicon.svg', $svg);
        $this->showInLogo($sandbox, '/favicon.svg', hash('sha256', $svg));

        $this->provider->exec($sandbox->external_id, [
            'sh', '-c',
            'for f in "$1/public/favicon.ico" "$1/public/apple-touch-icon.png"; do [ -f "$f" ] || continue; case "$(sha256sum "$f" | cut -d" " -f1)" in '.implode('|', self::STARTER_KIT).') rm -f "$f" ;; esac; done',
            'sh', $root,
        ]);
    }

    /**
     * Show the icon as the app's logo (the starter kit's sign-in pages and header), while the logo is still
     * the Laravel one or one written here; a logo the agent drew itself is kept.
     */
    protected function showInLogo(Sandbox $sandbox, string $url, string $hash): void
    {
        $src = $url.'?v='.substr($hash, 0, 12);
        $component = <<<TSX
        import type { ImgHTMLAttributes } from 'react';

        /** The app's logo: its icon ({$url}). @onedrop-app-icon (change the icon to change the logo) */
        export default function AppLogoIcon(props: ImgHTMLAttributes<HTMLImageElement>) {
            return <img src="{$src}" alt="" {...props} />;
        }

        TSX;

        $marks = implode(' ', array_map(fn (string $mark) => '-e '.escapeshellarg($mark), self::LOGO_MARKS));

        $this->provider->exec($sandbox->external_id, [
            'sh', '-c',
            'f="$1/'.self::LOGO_COMPONENT.'"; [ -f "$f" ] && grep -qF '.$marks.' "$f" || exit 0; printf %s "$APP_CONTENT" | cmp -s - "$f" || printf %s "$APP_CONTENT" > "$f"',
            'sh', WorkspaceFiles::ROOT,
        ], ['APP_CONTENT' => $component]);
    }

    protected function store(Project $project, string $bytes, string $mime): void
    {
        $disk = Storage::disk(self::disk());
        $extension = array_search($mime, self::MIME_TYPES, true) ?: 'bin';
        $path = "project-icons/{$project->id}/".Str::random(12).".{$extension}";

        $disk->put($path, $bytes);

        if ($project->icon_path && $project->icon_path !== $path) {
            $disk->delete($project->icon_path);
        }

        Project::withoutTimestamps(fn () => $project->update([
            'icon_path' => $path,
            'icon_mime' => $mime,
            'icon_hash' => hash('sha256', $bytes),
        ]));
    }

    /**
     * Ask for one simple, bold SVG that reads at 16 pixels.
     */
    protected function drawingPrompt(Project $project): string
    {
        $about = $project->messages()
            ->where('role', MessageRole::User)
            ->limit(3)
            ->pluck('content')
            ->prepend((string) $project->prompt)
            ->map(fn (string $text) => Str::limit(Str::squish($text), 800))
            ->filter()
            ->unique()
            ->implode("\n");

        return <<<PROMPT
        Design a favicon for this app. Reply with only the SVG code and nothing else.

        - Start with <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">.
        - Fill the canvas with a rounded square (rx="14") in one bold color that suits the app, or a linearGradient of two close colors.
        - On top, one simple white symbol that stands for what the app does, centered and big enough to read at 16 pixels.
        - Use only path, circle, rect, polygon, line, g, defs, linearGradient and stop. No text, images, styles, scripts or links.
        - Don't use any tools.

        <app>
        Name: {$project->name}
        {$about}
        </app>
        PROMPT;
    }
}
