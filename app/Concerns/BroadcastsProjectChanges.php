<?php

namespace App\Concerns;

use App\Events\ProjectUpdated;
use App\Models\Project;

/**
 * Tell the project's open pages when this model changes (LIVE-001).
 */
trait BroadcastsProjectChanges
{
    /**
     * Changes to only these columns don't change anything the project page shows.
     *
     * @var list<string>
     */
    protected static array $quietColumns = ['updated_at', 'files_version', 'events_token_hash', 'read_at', 'last_active_at', 'suspended_at', 'stopped_at'];

    public static function bootBroadcastsProjectChanges(): void
    {
        static::created(fn (self $model) => $model->signalProjectChange());

        static::updated(function (self $model) {
            if (array_diff(array_keys($model->getChanges()), self::$quietColumns) !== []) {
                $model->signalProjectChange();
            }
        });

        static::deleted(fn (self $model) => $model->signalProjectChange());
    }

    protected function signalProjectChange(): void
    {
        $projectId = $this instanceof Project ? $this->getKey() : $this->getAttribute('project_id');

        if ($projectId !== null) {
            ProjectUpdated::signal((int) $projectId);
        }
    }
}
