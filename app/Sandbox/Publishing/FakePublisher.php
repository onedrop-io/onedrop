<?php

namespace App\Sandbox\Publishing;

use App\Enums\PublishVisibility;
use App\Jobs\ConfirmPublication;
use App\Models\Project;

/**
 * In-memory publisher for tests.
 */
class FakePublisher implements Publisher
{
    /** @var array<int, PublishVisibility> project id => visibility */
    public array $published = [];

    public ?string $unavailable = null;

    /** When set, confirm() asks for browser approval until $approved is true. */
    public ?string $loginUrl = null;

    public bool $approved = false;

    /** When set ('funnel' or 'https'), confirm() waits for that tailnet feature to be turned on. */
    /** @var 'funnel'|'https'|null */
    public ?string $featureOff = null;

    public function unavailableReason(): ?string
    {
        return $this->unavailable;
    }

    public function start(Project $project): void {}

    public function confirm(Project $project, PublishVisibility $visibility): ?string
    {
        if ($this->loginUrl !== null && ! $this->approved) {
            throw new PublishNeedsLogin($this->loginUrl);
        }

        if ($this->featureOff !== null) {
            throw new PublishNeedsFeature($this->featureOff, 'https://login.tailscale.com/admin/acls/file');
        }

        $this->published[$project->id] = $visibility;

        return "https://{$project->publishHostname()}.example.ts.net";
    }

    public function confirmAttempts(): int
    {
        return ConfirmPublication::ATTEMPTS;
    }

    public function stop(Project $project): void
    {
        unset($this->published[$project->id]);
    }
}
