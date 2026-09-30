<?php

namespace App\Models;

use App\Concerns\BroadcastsProjectChanges;
use App\Enums\ShareCardStatus;
use Database\Factories\ProjectShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A project's public show-off page at /s/{slug} (SHARE-001). The row exists only while the project is shared.
 *
 * @property int $id
 * @property int $project_id
 * @property string $slug
 * @property string $prompt
 * @property string $page_path
 * @property ShareCardStatus|null $card_status
 * @property string|null $card_error
 * @property string|null $screenshot_file
 * @property string|null $card_file
 * @property Carbon|null $captured_at
 * @property int $views
 * @property int $remixes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'prompt', 'page_path', 'card_status', 'card_error', 'screenshot_file', 'card_file', 'captured_at', 'views', 'remixes'])]
class ProjectShare extends Model
{
    use BroadcastsProjectChanges;

    /** @use HasFactory<ProjectShareFactory> */
    use HasFactory;

    public const MAX_PROMPT = 2000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_status' => ShareCardStatus::class,
            'captured_at' => 'datetime',
            'views' => 'integer',
            'remixes' => 'integer',
        ];
    }

    /**
     * A readable, unguessable slug: the project's name plus a random part.
     */
    public static function slugFor(Project $project): string
    {
        $name = Str::of($project->name)->slug()->limit(40, '')->trim('-')->toString();

        return ($name !== '' ? "{$name}-" : '').Str::lower(Str::random(8));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function url(): string
    {
        return route('shares.show', $this);
    }

    /**
     * The preview card's address (changes when the card does, so crawlers and browsers refetch it).
     */
    public function cardUrl(): ?string
    {
        return $this->card_file ? route('shares.card', ['share' => $this, 'v' => $this->captured_at?->timestamp]) : null;
    }

    public function screenshotUrl(): ?string
    {
        return $this->screenshot_file ? route('shares.screenshot', ['share' => $this, 'v' => $this->captured_at?->timestamp]) : null;
    }
}
