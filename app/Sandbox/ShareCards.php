<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Enums\ShareCardStatus;
use App\Models\ProjectShare;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A share page's screenshot and preview card (SHARE-001). Headless Chromium in the project's sandbox takes a
 * screenshot of the app, then renders the card around it (docker/sandbox/share-card). Copies are kept on
 * OneDrop's disk, so the page and its link previews work while the sandbox is paused.
 */
class ShareCards
{
    public const DISK = 'local';

    public const SANDBOX_DIRECTORY = '/tmp/onedrop-share';

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Mark the card as being rendered, so the Share panel shows it and keeps polling.
     */
    public static function markCapturing(ProjectShare $share): void
    {
        $share->update(['card_status' => ShareCardStatus::Capturing, 'card_error' => null]);
    }

    /**
     * Take a new screenshot of the app and render the card from it.
     *
     * @throws SandboxException
     */
    public function capture(ProjectShare $share): void
    {
        $sandbox = $share->project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            throw new SandboxException(__("The project's sandbox isn't running."));
        }

        $result = $this->provider->exec($sandbox->external_id, ['/opt/zap/share-card', $share->page_path], [
            'CARD_HTML' => $this->cardHtml($share),
        ]);

        if (! $result->successful()) {
            throw new SandboxException(trim($result->errorOutput ?: $result->output) ?: __("Couldn't make the share card."));
        }

        $directory = storage_path('framework/share-card-'.Str::random(12));
        File::ensureDirectoryExists($directory);

        try {
            $this->provider->copyOut($sandbox->external_id, self::SANDBOX_DIRECTORY, $directory);

            $screenshot = $this->png("{$directory}/app.png");
            $card = $this->png("{$directory}/card.png");

            if ($screenshot === null || $card === null) {
                throw new SandboxException(__("The share card didn't copy out of the sandbox."));
            }

            $this->store($share, $screenshot, $card);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Remove the stored screenshot and card (when sharing stops or the project is deleted).
     */
    public function delete(int $projectId): void
    {
        Storage::disk(self::DISK)->deleteDirectory("project-shares/{$projectId}");
    }

    /**
     * The card's HTML, with the prompt sized to fit.
     */
    public function cardHtml(ProjectShare $share): string
    {
        $prompt = Str::limit(Str::squish($share->prompt), 240);
        $length = mb_strlen($prompt);
        [$size, $lines] = match (true) {
            $length <= 70 => [46, 5],
            $length <= 140 => [38, 6],
            default => [30, 8],
        };

        return view('share.card', [
            'prompt' => $prompt,
            'promptSize' => $size,
            'promptLines' => $lines,
            'name' => $share->project->name,
            'author' => self::author($share),
        ])->render();
    }

    /**
     * The owner's first name, as the page and card credit them.
     */
    public static function author(ProjectShare $share): ?string
    {
        return Str::of((string) $share->project->user?->name)->squish()->before(' ')->toString() ?: null;
    }

    /**
     * Count a view of the page, at most once an hour per visitor.
     */
    public static function countView(ProjectShare $share, string $visitor): void
    {
        if (Cache::add("share-view:{$share->id}:".hash('sha256', $visitor), true, now()->addHour())) {
            $share->timestamps = false;
            $share->increment('views');
            $share->timestamps = true;
        }
    }

    protected function png(string $path): ?string
    {
        if (! File::exists($path)) {
            return null;
        }

        $bytes = File::get($path);

        return @getimagesizefromstring($bytes)[2] === IMAGETYPE_PNG ? $bytes : null;
    }

    protected function store(ProjectShare $share, string $screenshot, string $card): void
    {
        $disk = Storage::disk(self::DISK);
        $prefix = "project-shares/{$share->project_id}/".Str::random(12);
        $old = array_filter([$share->screenshot_file, $share->card_file]);

        $disk->put("{$prefix}-app.png", $screenshot);
        $disk->put("{$prefix}-card.png", $card);

        $share->update([
            'screenshot_file' => "{$prefix}-app.png",
            'card_file' => "{$prefix}-card.png",
            'card_status' => ShareCardStatus::Ready,
            'card_error' => null,
            'captured_at' => now(),
        ]);

        $disk->delete($old);

        // Sharing stopped while the card was being made: don't keep its images.
        if (! ProjectShare::whereKey($share->id)->exists()) {
            $disk->delete(["{$prefix}-app.png", "{$prefix}-card.png"]);
        }
    }
}
