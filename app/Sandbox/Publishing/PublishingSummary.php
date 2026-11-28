<?php

namespace App\Sandbox\Publishing;

use App\Enums\PublishTarget;
use App\Models\Project;
use App\Sandbox\Hosting\HostingProviders;
use Throwable;

/**
 * What the agent needs to answer "how do I deploy this?" with what OneDrop actually offers this project (PUB-004):
 * where it's published now, the targets on offer here, and, when Hosting is set up, its platforms and whose account
 * each provider uses. It's sent with every run and written to the sandbox for `guides/publishing.md`; no keys or
 * account details, only names and which kind of account.
 */
class PublishingSummary
{
    public function __construct(
        protected Publishers $publishers,
        protected HostingProviders $hosting,
    ) {}

    /**
     * @return array{
     *     published: array{target: string, url: string, visibility: string|null, status: string|null}|null,
     *     targets: list<array{target: string, label: string, private: string|null, public: string, unavailable: string|null}>,
     *     hosting: array{platforms: list<string>, accounts: array<string, 'organization'|'install'|'none'>}|null,
     * }
     */
    public function for(Project $project): array
    {
        $organization = $project->organization;

        return [
            'published' => $project->published_url === null ? null : [
                'target' => ($project->publish_target ?? PublishTarget::Tailscale)->value,
                'url' => $project->published_url,
                'visibility' => $project->publish_visibility?->value,
                'status' => $project->publish_status?->value,
            ],
            'targets' => $this->publishers->options($project),
            'hosting' => ! $this->hosting->available($organization) ? null : [
                'platforms' => array_column($this->hosting->platforms($organization), 'label'),
                'accounts' => collect(HostingProviders::PROVIDERS)->mapWithKeys(fn (array $provider, string $name): array => [
                    $provider['label'] => match (true) {
                        $organization !== null && $this->hosting->organizationAccount($organization, $name) !== null => 'organization',
                        $this->hosting->platformAccount($name) !== null => 'install',
                        default => 'none',
                    },
                ])->all(),
            ],
        ];
    }

    /**
     * The summary as JSON for the agent's environment; empty when it can't be worked out, which never stops a run.
     */
    public function json(Project $project): string
    {
        try {
            return json_encode($this->for($project), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }
}
