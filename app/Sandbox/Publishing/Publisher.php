<?php

namespace App\Sandbox\Publishing;

use App\Enums\PublishVisibility;
use App\Models\Project;

/**
 * Makes a project's running sandbox reachable at its own URL.
 * Like providers, every method is a short call; slow steps are retried by jobs.
 */
interface Publisher
{
    /**
     * Why publishing can't be used right now, or null when it can.
     */
    public function unavailableReason(): ?string;

    /**
     * Begin publishing (or re-publishing) the project's sandbox.
     *
     * @throws PublishException
     */
    public function start(Project $project): void;

    /**
     * Finish once the endpoint is up: apply the visibility and return the public URL,
     * or null if it isn't ready yet (the caller retries).
     *
     * @throws PublishNeedsLogin when someone must approve it in the browser first (the caller keeps retrying)
     * @throws PublishException
     */
    public function confirm(Project $project, PublishVisibility $visibility): ?string;

    /**
     * Take the project offline. Unpublishing something not published is not an error.
     *
     * @throws PublishException
     */
    public function stop(Project $project): void;
}
