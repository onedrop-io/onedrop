<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One deploy of a project to hosting (HOST-001): its code packed in the sandbox, its services made, an image built and
 * released. Deployer moves it through its steps.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property int $number
 * @property string|null $kind server or static
 * @property DeploymentStatus $status
 * @property string $step
 * @property array<string, mixed>|null $state
 * @property int $polls
 * @property string|null $image
 * @property string|null $commit the sandbox's commit it shipped
 * @property string|null $url
 * @property string|null $log
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'number', 'kind', 'status', 'step', 'state', 'polls', 'image', 'commit', 'url', 'log', 'error', 'finished_at'])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    /** The most of the log kept, from the end. */
    public const LOG_BYTES = 20000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'state' => 'array',
            'polls' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add a line to the log the Publish panel shows, keeping its end.
     */
    public function note(string $line): void
    {
        $log = ltrim(($this->log ?? '')."\n".now()->format('H:i:s').' '.$line);

        $this->update(['log' => strlen($log) > self::LOG_BYTES ? substr($log, -self::LOG_BYTES) : $log]);
    }

    /**
     * A value a step left for the next ones.
     */
    public function remembered(string $key, mixed $default = null): mixed
    {
        return data_get($this->state, $key, $default);
    }

    /**
     * Keep values for the next steps.
     *
     * @param  array<string, mixed>  $values
     */
    public function remember(array $values): void
    {
        $this->update(['state' => array_replace($this->state ?? [], $values)]);
    }
}
