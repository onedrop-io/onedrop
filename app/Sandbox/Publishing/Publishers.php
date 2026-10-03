<?php

namespace App\Sandbox\Publishing;

use App\Enums\PublishTarget;
use App\Models\Project;
use App\Sandbox\Hosting\Deployer;
use App\Sandbox\Hosting\HostingProviders;

/**
 * The places a project can be published, and who can open it there. On a server both its own domain and Tailscale
 * are offered; elsewhere, Tailscale. Hosting (HOST-001) is offered wherever an admin or the project's organization set
 * up a hosting provider.
 */
class Publishers
{
    /**
     * The publisher for a target. Resolved on each call, so tests can swap the Tailscale one (bound as Publisher).
     */
    public function for(PublishTarget $target): Publisher
    {
        return match ($target) {
            PublishTarget::Domain => app(DomainPublisher::class),
            PublishTarget::Tailscale => app(Publisher::class),
            PublishTarget::Hosting => app(HostingPublisher::class),
        };
    }

    /**
     * Where a project is (or was last) published; projects from before there was a choice used Tailscale.
     */
    public function forProject(Project $project): Publisher
    {
        return $this->for($project->publish_target ?? PublishTarget::Tailscale);
    }

    /**
     * The targets on offer, the domain first when it works, then hosting when it's set up, each with who "Private" and
     * "Public" mean there (null when it can't be private: hosted apps are public for now).
     *
     * @return list<array{target: string, label: string, private: string|null, public: string, unavailable: string|null}>
     */
    public function options(?Project $project = null): array
    {
        $domain = $this->for(PublishTarget::Domain)->unavailableReason() === null ? [[
            'target' => PublishTarget::Domain->value,
            'label' => __('Your domain'),
            'private' => __('People signed in to OneDrop'),
            'public' => __('Anyone on the internet with the URL'),
            'unavailable' => null,
        ]] : [];

        $hosting = $project && app(HostingProviders::class)->available($project->organization) ? [[
            'target' => PublishTarget::Hosting->value,
            'label' => __('Hosting'),
            'private' => null,
            'public' => __('Anyone on the internet with the URL. Runs on its own, off the sandbox.'),
            'unavailable' => app(Deployer::class)->unavailableReason($project),
        ]] : [];

        return [...$domain, ...$hosting, [
            'target' => PublishTarget::Tailscale->value,
            'label' => 'Tailscale',
            'private' => __("People on your team's tailnet"),
            'public' => __('Anyone on the internet with the URL'),
            'unavailable' => $this->for(PublishTarget::Tailscale)->unavailableReason(),
        ]];
    }

    /**
     * Why the project can't be published to a target now, or null when it can.
     */
    public function unavailableReason(PublishTarget $target, Project $project): ?string
    {
        if ($target === PublishTarget::Hosting) {
            return app(HostingProviders::class)->available($project->organization)
                ? app(Deployer::class)->unavailableReason($project)
                : "Hosting isn't set up on this install.";
        }

        return $this->for($target)->unavailableReason();
    }

    /**
     * Who can open the project where it's published, e.g. "People signed in to OneDrop"; null when it isn't.
     */
    public function audience(Project $project): ?string
    {
        if ($project->publish_visibility === null || $project->published_url === null) {
            return null;
        }

        $target = ($project->publish_target ?? PublishTarget::Tailscale)->value;
        $option = collect($this->options($project))->firstWhere('target', $target);

        return $option[$project->publish_visibility->value] ?? null;
    }

    /**
     * The target used when none is chosen: the first one on offer that works.
     */
    public function default(?Project $project = null): PublishTarget
    {
        foreach ($this->options($project) as $option) {
            if ($option['unavailable'] === null) {
                return PublishTarget::from($option['target']);
            }
        }

        return PublishTarget::Tailscale;
    }
}
