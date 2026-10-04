<?php

namespace App\Sandbox\Domains;

use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Enums\HostedServiceKind;
use App\Enums\PublishTarget;
use App\Jobs\CheckProjectDomain;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Sandbox\Gateway;
use App\Sandbox\Hosting\CloudflareApi;
use App\Sandbox\Hosting\FlyApi;
use App\Sandbox\Hosting\HostedServices;
use App\Sandbox\Hosting\HostingAccount;
use App\Sandbox\Hosting\HostingException;
use App\Sandbox\Publishing\Publishers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A project's custom domains (DOM-001..004): adding and removing them, connecting each one where the project is
 * published, checking them, and keeping the primary one as the project's published URL.
 */
class ProjectDomains
{
    public function __construct(
        protected Gateway $gateway,
        protected DomainDns $dns,
        protected HostedServices $services,
        protected Publishers $publishers,
    ) {}

    /**
     * Add a domain to the project and start connecting it. The project's first domain is its primary.
     *
     * @throws ValidationException
     */
    public function add(Project $project, string $hostname): ProjectDomain
    {
        $hostname = self::normalize($hostname);

        if ($problem = $this->problemWith($hostname, $project)) {
            throw ValidationException::withMessages(['hostname' => $problem]);
        }

        $domain = $project->domains()->create([
            'hostname' => $hostname,
            'primary' => ! $project->domains()->where('primary', true)->exists(),
            'status' => DomainStatus::Pending,
        ]);

        $this->connect($domain, $this->connector($project));
        $this->startChecking($domain);

        return $domain;
    }

    /**
     * Stop serving the domain and forget it. Removing the primary makes the next one primary (an active one first).
     *
     * @throws ValidationException when the provider couldn't remove it
     */
    public function remove(ProjectDomain $domain): void
    {
        try {
            $this->connectorNamed($domain)?->detach($domain);
        } catch (HostingException $e) {
            throw ValidationException::withMessages(['hostname' => $e->getMessage()]);
        }

        $project = $domain->project;
        $domain->delete();

        if ($domain->primary) {
            $project->domains()->orderByRaw('case when status = ? then 0 else 1 end', [DomainStatus::Active->value])->orderBy('id')->first()?->update(['primary' => true]);
        }

        $this->refreshUrl($project);
    }

    public function makePrimary(ProjectDomain $domain): void
    {
        DB::transaction(function () use ($domain) {
            $domain->project->domains()->whereKeyNot($domain->id)->update(['primary' => false]);
            $domain->update(['primary' => true]);
        });

        $this->refreshUrl($domain->project);
    }

    /**
     * Connect every domain where the project is published now (after publishing, or moving to another target).
     */
    public function sync(Project $project): void
    {
        $connector = $this->connector($project);

        foreach ($project->domains()->get() as $domain) {
            if ($connector instanceof Connector && $domain->via === $connector->name()) {
                continue;
            }

            $this->connect($domain, $connector);
            $this->startChecking($domain);
        }

        $this->refreshUrl($project);
    }

    /**
     * Check a domain now: connect it first if it isn't connected where the project is published.
     */
    public function check(ProjectDomain $domain): void
    {
        $project = $domain->project;
        $connector = $this->connector($project);

        if (! $connector instanceof Connector || $domain->via !== $connector->name()) {
            $this->connect($domain, $connector);

            if (! $connector instanceof Connector || $domain->via === null) {
                return;
            }
        }

        try {
            $problem = $connector->check($domain);
        } catch (DomainException|HostingException $e) {
            $problem = $e->getMessage();
        }

        $domain->fill([
            'status' => $problem === null ? DomainStatus::Active : DomainStatus::Pending,
            'error' => $problem,
            'checked_at' => now(),
            'verified_at' => $problem === null ? ($domain->verified_at ?? now()) : null,
        ])->save();

        $this->refreshUrl($project);
    }

    /**
     * Check a domain again soon, and keep checking (CheckProjectDomain) until it's active or two days pass.
     */
    public function startChecking(ProjectDomain $domain): void
    {
        $domain->update(['checking_since' => now()]);

        if ($domain->via !== null && ! $domain->isActive()) {
            CheckProjectDomain::dispatch($domain, $domain->checking_since->getTimestamp())->delay(now()->addSeconds(CheckProjectDomain::FAST_SECONDS));
        }
    }

    /**
     * Stop serving every domain at its provider (before the project is deleted, DOM-004).
     */
    public function removeAll(Project $project): void
    {
        foreach ($project->domains()->get() as $domain) {
            try {
                $this->connectorNamed($domain)?->detach($domain);
            } catch (HostingException $e) {
                report($e);
            }
        }
    }

    /**
     * Where the project's domains are served now, or why they can't be.
     */
    public function connector(Project $project): Connector|string
    {
        $target = $project->publish_target ?? $this->publishers->default($project);

        if ($target === PublishTarget::Tailscale) {
            return __("Tailscale can't use your own domain. Publish to Your domain or Hosting to connect it.");
        }

        return $target === PublishTarget::Domain ? $this->gatewayConnector() : $this->hostingConnector($project);
    }

    /**
     * The project's published URL: its primary domain once that's active and connected where it's published,
     * otherwise the target's own address (DOM-002).
     */
    public function canonicalUrl(Project $project): ?string
    {
        if ($project->published_default_url === null) {
            return null;
        }

        $primary = $project->domains()->where('primary', true)->where('status', DomainStatus::Active)->first();
        $connector = $primary ? $this->connector($project) : null;

        return $connector instanceof Connector && $primary->via === $connector->name()
            ? $primary->url()
            : $project->published_default_url;
    }

    public function refreshUrl(Project $project): void
    {
        $url = $this->canonicalUrl($project);

        if ($url !== null && $url !== $project->published_url) {
            $project->update(['published_url' => $url]);
        }
    }

    /**
     * A hostname as typed ("https://Example.com/", "www.example.com:443") as a bare lowercase ASCII name.
     */
    public static function normalize(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $hostname);
        $hostname = (string) preg_replace('#[/?\#].*$#', '', $hostname);
        $hostname = (string) preg_replace('#:\d+$#', '', $hostname);
        $hostname = rtrim($hostname, '.');

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $hostname)) {
            $hostname = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $hostname;
        }

        return $hostname;
    }

    /**
     * Why a hostname can't be added to the project, or null when it can.
     */
    protected function problemWith(string $hostname, Project $project): ?string
    {
        $valid = strlen($hostname) <= 253
            && filter_var($hostname, FILTER_VALIDATE_IP) === false
            && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $hostname);

        if (! $valid) {
            return __('Enter a domain name, like example.com or app.example.com.');
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $gatewayDomain = strtolower((string) $this->gateway->domain());

        if ($hostname === $appHost || ($gatewayDomain !== '' && ($hostname === $gatewayDomain || str_ends_with($hostname, ".{$gatewayDomain}")))) {
            return __(':host is this app builder\'s own domain. Use a domain of yours.', ['host' => $hostname]);
        }

        $existing = ProjectDomain::where('hostname', $hostname)->first();

        if ($existing) {
            return $existing->project_id === $project->id
                ? __(':host is already added.', ['host' => $hostname])
                : __(':host is connected to another project.', ['host' => $hostname]);
        }

        return null;
    }

    /**
     * Move a domain to a connector (or mark it not connected, with why), detaching it from where it was. Not saved
     * until the end; the caller starts checking it.
     */
    protected function connect(ProjectDomain $domain, Connector|string $connector): void
    {
        if ($domain->via !== null) {
            try {
                $this->connectorNamed($domain)?->detach($domain);
            } catch (HostingException $e) {
                report($e);
            }
        }

        $domain->fill(['via' => null, 'external_id' => null, 'records' => null, 'status' => DomainStatus::Pending, 'verified_at' => null]);

        if (is_string($connector)) {
            $domain->fill(['error' => $connector])->save();

            return;
        }

        try {
            $connector->attach($domain);
            $domain->fill(['via' => $connector->name(), 'error' => null]);
        } catch (DomainException|HostingException $e) {
            $domain->fill(['error' => $e->getMessage()]);
        }

        $domain->save();
    }

    /**
     * The connector a domain is attached through now (to detach it), or null when that's no longer reachable.
     */
    protected function connectorNamed(ProjectDomain $domain): ?Connector
    {
        try {
            return match ($domain->via) {
                'caddy', 'cloudflare-saas' => ($connector = $this->gatewayConnector()) instanceof Connector && $connector->name() === $domain->via ? $connector : null,
                'fly' => ($app = $domain->project->hostedServices()->where('kind', HostedServiceKind::App)->first())
                    ? new FlyConnector(new FlyApi($this->services->accountFor($app)), $app->name)
                    : null,
                'cloudflare-worker' => ($site = $domain->project->hostedServices()->where('kind', HostedServiceKind::Site)->first())
                    ? new CloudflareWorkerConnector(new CloudflareApi($this->services->accountFor($site)), $site->name)
                    : null,
                default => null,
            };
        } catch (HostingException) {
            return null;
        }
    }

    protected function gatewayConnector(): Connector|string
    {
        if (! $this->gateway->enabled()) {
            return __('Publishing to your own domain needs a server install with a domain.');
        }

        if (! $this->gateway->viaWorker()) {
            return new CaddyConnector((string) $this->gateway->domain(), $this->dns);
        }

        $config = config('sandbox.gateway_domains');

        if (blank($config['zone_id'] ?? null) || blank($config['api_token'] ?? null) || blank($config['target'] ?? null)) {
            return __('Custom domains aren\'t set up on this install yet: an admin needs to turn on Cloudflare for SaaS for the preview gateway.');
        }

        return new CloudflareSaasConnector(
            new CloudflareApi(new HostingAccount('cloudflare', 'platform', ['api_token' => $config['api_token']])),
            $config['zone_id'],
            $config['target'],
        );
    }

    /**
     * Hosted apps: a Fly certificate for an app with a server, a Workers custom domain for a front end, going by
     * what the latest live deploy was.
     */
    protected function hostingConnector(Project $project): Connector|string
    {
        $kind = $project->deployments()->where('status', DeploymentStatus::Live)->latest('id')->value('kind');
        $service = match ($kind) {
            'static' => $project->hostedServices()->where('kind', HostedServiceKind::Site)->first(),
            'server' => $project->hostedServices()->where('kind', HostedServiceKind::App)->first(),
            default => null,
        };

        if (! $service) {
            return __('Your domain connects after the first deploy to Hosting.');
        }

        try {
            $account = $this->services->accountFor($service);
        } catch (HostingException $e) {
            return $e->getMessage();
        }

        return $kind === 'static'
            ? new CloudflareWorkerConnector(new CloudflareApi($account), $service->name)
            : new FlyConnector(new FlyApi($account), $service->name);
    }
}
