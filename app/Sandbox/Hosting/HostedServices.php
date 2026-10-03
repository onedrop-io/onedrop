<?php

namespace App\Sandbox\Hosting;

use App\Enums\HostedServiceKind;
use App\Jobs\DestroyHostedService;
use App\Models\HostedService;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * What a hosted project has at its providers (HOST-001, HOST-002): made the first time a deploy needs it, in the
 * organization's own account or the install's, and kept there. Only the app's own connection details reach it.
 */
class HostedServices
{
    /** What each kind does, for picking its provider. */
    protected const ROLES = [
        'app' => 'server',
        'volume' => 'volume',
        'postgres' => 'postgres',
        'redis' => 'redis',
        'bucket' => 'bucket',
        'site' => 'static',
        'backup' => 'bucket',
    ];

    public function __construct(protected HostingProviders $providers) {}

    /**
     * The project's service of this kind, made now when it has none.
     *
     * @throws HostingException
     */
    public function ensure(Project $project, HostedServiceKind $kind): HostedService
    {
        $existing = $project->hostedServices()->where('kind', $kind)->first();

        if ($existing) {
            return $existing;
        }

        $account = $kind === HostedServiceKind::Volume
            ? $this->accountFor($this->ensure($project, HostedServiceKind::App))
            : $this->account($project, $kind);
        $name = $this->name($project, $kind);

        $made = match ($kind) {
            HostedServiceKind::App => $this->makeApp($account, $name),
            HostedServiceKind::Volume => $this->makeVolume($account, $project),
            HostedServiceKind::Postgres => $this->makePostgres($account, $name),
            HostedServiceKind::Redis => $this->makeRedis($account, $name),
            HostedServiceKind::Bucket, HostedServiceKind::Backup => $this->makeBucket($account, $name),
            // The site's Worker is made by uploading to it.
            HostedServiceKind::Site => ['external_id' => $name, 'details' => []],
        };

        return $project->hostedServices()->create([
            'kind' => $kind,
            'provider' => $account->provider,
            'owner' => $account->owner,
            'name' => $name,
            'region' => $made['region'] ?? null,
            ...$made,
        ]);
    }

    /**
     * The account to make a new service of this kind in, or a message saying who has to set one up.
     *
     * @throws HostingException
     */
    public function account(Project $project, HostedServiceKind $kind): HostingAccount
    {
        $provider = $this->providers->providerFor(self::ROLES[$kind->value]);

        return $this->providers->account($project->organization, $provider)
            ?? throw new HostingException(__(':kind needs :provider: an admin can set it up in Settings → Hosting, or your organization can connect its own account.', [
                'kind' => $kind->label(),
                'provider' => HostingProviders::PROVIDERS[$provider]['label'],
            ]));
    }

    /**
     * The account a service was made in.
     *
     * @throws HostingException
     */
    public function accountFor(HostedService $service): HostingAccount
    {
        return $this->providers->accountFor($service)
            ?? throw new HostingException(__('The :provider account the :kind is in isn\'t connected any more.', [
                'provider' => HostingProviders::PROVIDERS[$service->provider]['label'] ?? $service->provider,
                'kind' => strtolower($service->kind->label()),
            ]));
    }

    /**
     * The environment the hosted app gets for its managed services: the same names it reads in its sandbox, where they
     * point at local ones (guides/hosting.md).
     *
     * @return array<string, string>
     */
    public function environment(Project $project): array
    {
        $env = [];

        foreach ($project->hostedServices()->get() as $service) {
            $details = $service->details ?? [];

            $env += match ($service->kind) {
                HostedServiceKind::Postgres => [
                    'DATABASE_URL' => $details['url'],
                    'DB_CONNECTION' => 'pgsql',
                    'DB_URL' => $details['url'],
                ],
                HostedServiceKind::Redis => ['REDIS_URL' => $details['url']],
                HostedServiceKind::Bucket => [
                    'AWS_ACCESS_KEY_ID' => $details['access_key_id'],
                    'AWS_SECRET_ACCESS_KEY' => $details['secret_access_key'],
                    'AWS_DEFAULT_REGION' => 'auto',
                    'AWS_REGION' => 'auto',
                    'AWS_BUCKET' => $details['bucket'],
                    'AWS_ENDPOINT' => $details['endpoint'],
                    'AWS_ENDPOINT_URL_S3' => $details['endpoint'],
                    'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
                    'S3_BUCKET' => $details['bucket'],
                    'S3_ENDPOINT' => $details['endpoint'],
                ],
                default => [],
            };
        }

        return $env;
    }

    /**
     * The project's services for the Publish panel: what each is, where, and whose account it's in.
     *
     * @return list<array{id: int, kind: string, label: string, provider: string, owner: string, holds_data: bool}>
     */
    public function describe(Project $project): array
    {
        return array_values($project->hostedServices()->orderBy('id')->get()->map(fn (HostedService $service) => [
            'id' => $service->id,
            'kind' => $service->kind->value,
            'label' => $service->kind->label(),
            'provider' => HostingProviders::PROVIDERS[$service->provider]['label'] ?? $service->provider,
            'owner' => $service->owner,
            'holds_data' => $service->kind->holdsData(),
        ])->all());
    }

    /**
     * Delete every service the project has, in the background, at its provider (the project is being deleted, or its
     * user deleted its hosted data). The Fly app goes last: deleting it takes its volume and machines with it.
     */
    public function destroyAll(Project $project): void
    {
        $services = $project->hostedServices()->get()->sortBy(fn (HostedService $service) => $service->kind === HostedServiceKind::App ? 1 : 0);
        $app = $services->firstWhere('kind', HostedServiceKind::App);

        foreach ($services as $service) {
            // The app's deletion takes its volume with it.
            if ($service->kind !== HostedServiceKind::Volume || ! $app) {
                DestroyHostedService::dispatch($this->snapshot($service, $app));
            }

            $service->delete();
        }
    }

    /**
     * Delete something at its provider, from what destroyAll() kept of it.
     *
     * @param  array{kind: string, provider: string, owner: string, organization_id: int, name: string, external_id: string|null, details: array<string, mixed>, app: string|null}  $service
     *
     * @throws HostingException
     */
    public function destroy(array $service): void
    {
        $organization = Organization::query()->find($service['organization_id']);
        $account = $service['owner'] === HostedService::OWNER_ORGANIZATION
            ? ($organization ? $this->providers->organizationAccount($organization, $service['provider']) : null)
            : $this->providers->platformAccount($service['provider'], requireEnabled: false);

        if ($account === null) {
            throw new HostingException("The {$service['provider']} account {$service['name']} is in isn't connected, so it wasn't deleted.");
        }

        match (HostedServiceKind::from($service['kind'])) {
            HostedServiceKind::App => (new FlyApi($account))->deleteApp($service['name']),
            HostedServiceKind::Volume => $service['app'] && $service['external_id'] ? (new FlyApi($account))->deleteVolume($service['app'], $service['external_id']) : null,
            HostedServiceKind::Postgres => (new NeonApi($account))->deleteProject((string) $service['external_id']),
            HostedServiceKind::Redis => (new UpstashApi($account))->deleteDatabase((string) $service['external_id']),
            HostedServiceKind::Bucket, HostedServiceKind::Backup => (new CloudflareApi($account))->deleteBucket($service['name'], $service['details']['token_id'] ?? null),
            HostedServiceKind::Site => (new CloudflareApi($account))->deleteSite($service['name']),
        };
    }

    /**
     * A name for something new: the project's name and id, plus a few random letters, since Fly app and Worker names
     * are shared by everyone.
     */
    public function name(Project $project, HostedServiceKind $kind): string
    {
        $slug = Str::limit(Str::slug($project->name), 24, '');

        $name = trim(($slug === '' ? 'app' : $slug)."-{$project->id}-".Str::lower(Str::random(4)), '-');

        return $kind === HostedServiceKind::Backup ? "{$name}-backups" : $name;
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeApp(HostingAccount $account, string $name): array
    {
        (new FlyApi($account))->createApp($name);

        return ['external_id' => $name, 'region' => $account->get('region', 'iad'), 'details' => []];
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeVolume(HostingAccount $account, Project $project): array
    {
        $app = $this->ensure($project, HostedServiceKind::App);
        $region = $app->region ?? $account->get('region', 'iad');
        $id = (new FlyApi($account))->createVolume($app->name, $region, (int) $account->get('volume_gb', 1));

        return ['external_id' => $id, 'region' => $region, 'details' => ['app' => $app->name]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function makePostgres(HostingAccount $account, string $name): array
    {
        ['id' => $id, 'url' => $url] = (new NeonApi($account))->createProject($name);

        return ['external_id' => $id, 'region' => $account->get('region'), 'details' => ['url' => $url]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeRedis(HostingAccount $account, string $name): array
    {
        ['id' => $id, 'url' => $url] = (new UpstashApi($account))->createDatabase($name);

        return ['external_id' => $id, 'region' => $account->get('region'), 'details' => ['url' => $url]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeBucket(HostingAccount $account, string $name): array
    {
        $bucket = (new CloudflareApi($account))->createBucket($name);

        return ['external_id' => $name, 'details' => $bucket];
    }

    /**
     * @return array{kind: string, provider: string, owner: string, organization_id: int, name: string, external_id: string|null, details: array<string, mixed>, app: string|null}
     */
    protected function snapshot(HostedService $service, ?HostedService $app): array
    {
        return [
            'kind' => $service->kind->value,
            'provider' => $service->provider,
            'owner' => $service->owner,
            'organization_id' => $service->project->organization_id,
            'name' => $service->name,
            'external_id' => $service->external_id,
            // Only what deleting needs: connection details stay out of the queue.
            'details' => array_intersect_key($service->details ?? [], ['token_id' => true]),
            'app' => $service->details['app'] ?? $app?->name,
        ];
    }
}
