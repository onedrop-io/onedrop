<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $prompt
 * @property ProjectStatus $status
 * @property string|null $agent_session_id
 * @property PublishStatus|null $publish_status
 * @property PublishVisibility|null $publish_visibility
 * @property string|null $published_url
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property string|null $publish_error
 * @property string|null $publish_login_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'prompt', 'status', 'agent_session_id', 'publish_status', 'publish_visibility', 'published_url', 'published_at', 'published_by', 'publish_error', 'publish_login_url'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'publish_status' => PublishStatus::class,
            'publish_visibility' => PublishVisibility::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * Turn a free-form description into a short project name.
     */
    public static function nameFromPrompt(string $prompt): string
    {
        $name = Str::of($prompt)->squish()->words(6, '')->rtrim('.,!?;:')->title()->toString();

        return Str::limit($name, 60, '') ?: 'Untitled Project';
    }

    /**
     * The user who owns the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The chat messages in the project, oldest first.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * The sandbox the project runs in.
     *
     * @return HasOne<Sandbox, $this>
     */
    public function sandbox(): HasOne
    {
        return $this->hasOne(Sandbox::class);
    }

    /**
     * The user who last published the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * A DNS-safe hostname for the published app, unique per project.
     */
    public function publishHostname(): string
    {
        $slug = Str::of($this->name)->slug()->limit(40, '')->trim('-')->toString();

        return ($slug !== '' ? "{$slug}-" : 'project-').$this->id;
    }
}
