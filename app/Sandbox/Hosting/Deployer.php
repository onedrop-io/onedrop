<?php

namespace App\Sandbox\Hosting;

use App\Enums\DeploymentStatus;
use App\Enums\HostedServiceKind;
use App\Enums\SandboxStatus;
use App\Jobs\AdvanceDeployment;
use App\Models\Deployment;
use App\Models\HostedService;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxTools;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deploys a project to hosting (HOST-001, HOST-002), one step at a time, each a queued AdvanceDeployment:
 *
 *  inspect    read .onedrop/host.json in the sandbox: a front end (static) or an app with a server, its services and data
 *  package    build and pack it in the sandbox, which uploads the files straight to release storage (ReleaseStorage)
 *             through signed links (with its data too, the first time)
 *  provision  make what it's missing: the Fly app, a volume, Postgres, Redis, a bucket
 *  upload     (front ends) put the files on a Cloudflare Worker; done
 *  build      start a builder machine in the Fly app that adds the release to the base image and pushes it to Fly's
 *             registry (and copies the sandbox's Postgres into the new one, the first time)
 *  building   wait for it to finish
 *  release    start (or update) the app's machine on the new image
 *  verify     wait until it answers; if it never does, put the last good image back
 *
 * Provider keys stay here: the sandbox only gets signed links, the builder only its own account's token.
 */
class Deployer
{
    /** Seconds between checks while something runs elsewhere. */
    public const POLL_SECONDS = 5;

    /** How long a build may take (at POLL_SECONDS each). */
    public const BUILD_POLLS = 360;

    /** How long the app may take to answer after it's released. */
    public const VERIFY_POLLS = 60;

    /** How long a signed link to a release's files lasts, in minutes: the first boot reads the data from one. */
    protected const LINK_MINUTES = 1440;

    protected const SCRIPT = SandboxTools::PATH.'/hosting';

    /** The port the sandbox's proxy listens on (it rewrites addresses, then hands requests to the app). */
    protected const PROXY_PORT = 8081;

    public function __construct(
        protected SandboxProvider $sandboxes,
        protected SandboxTools $tools,
        protected HostedServices $services,
        protected HostingProviders $providers,
        protected ReleaseStorage $releases,
        protected HostingChanges $changes,
    ) {}

    /** @var array<int, Filesystem> release disks by project, made once per job */
    protected array $disks = [];

    /**
     * Why projects can't be deployed here, or null when they can.
     */
    public function unavailableReason(Project $project): ?string
    {
        if (! $this->providers->available($project->organization)) {
            return __('Hosting needs Fly.io or Cloudflare: an admin can set them up in Settings → Hosting, or your organization can connect its own accounts.');
        }

        if (! $this->releases->available($project)) {
            return __('Hosting needs somewhere to keep releases: turn on Cloudflare in Settings → Hosting, or use an S3-compatible SANDBOX_SNAPSHOT_DISK.');
        }

        return null;
    }

    /**
     * Start a new deployment of the project. A deployment still running stops where it is.
     */
    public function start(Project $project, ?User $user): Deployment
    {
        $deployment = DB::transaction(function () use ($project, $user) {
            $project->deployments()->where('status', DeploymentStatus::Running)->update([
                'status' => DeploymentStatus::Failed,
                'error' => 'A newer deploy replaced this one.',
                'finished_at' => now(),
            ]);

            return $project->deployments()->create([
                'user_id' => $user?->id,
                'number' => (int) $project->deployments()->max('number') + 1,
                'status' => DeploymentStatus::Running,
                'step' => 'inspect',
            ]);
        });

        AdvanceDeployment::dispatch($deployment);

        return $deployment;
    }

    /**
     * The project's latest deployment.
     */
    public function latest(Project $project): ?Deployment
    {
        return $project->deployments()->latest('id')->first();
    }

    /**
     * Run the deployment's current step. Returns how many seconds to wait before running the next, or null when it's
     * finished (live or failed).
     */
    public function advance(Deployment $deployment): ?int
    {
        if ($deployment->status !== DeploymentStatus::Running) {
            return null;
        }

        try {
            return match ($deployment->step) {
                'inspect' => $this->inspect($deployment),
                'package' => $this->package($deployment),
                'provision' => $this->provision($deployment),
                'upload' => $this->upload($deployment),
                'build' => $this->build($deployment),
                'building' => $this->building($deployment),
                'release' => $this->release($deployment),
                'verify' => $this->verify($deployment),
                default => throw new HostingException("Unknown step {$deployment->step}."),
            };
        } catch (HostingException|SandboxException $e) {
            $this->fail($deployment, $e->getMessage());

            return null;
        }
    }

    /**
     * Mark it failed, keeping the last good version running, and clean up.
     */
    public function fail(Deployment $deployment, string $reason): void
    {
        $deployment->note("Failed: {$reason}");
        $deployment->update(['status' => DeploymentStatus::Failed, 'error' => $reason, 'finished_at' => now()]);
        $this->removeBuilder($deployment);
        $this->cleanUp($deployment);
    }

    /**
     * What the hosted app looks like in the sandbox.
     *
     * @throws HostingException|SandboxException
     */
    protected function inspect(Deployment $deployment): int
    {
        $sandbox = $this->sandbox($deployment->project);
        $manifest = json_decode($this->run($sandbox, [self::SCRIPT, 'inspect'], [], "Couldn't read the app's hosting settings"), true);

        if (! is_array($manifest)) {
            throw new HostingException("Couldn't read the app's hosting settings.");
        }

        $kind = filled($manifest['static'] ?? null) ? 'static' : 'server';
        $deployment->update(['kind' => $kind]);
        $deployment->remember(['manifest' => $manifest]);
        $deployment->note($kind === 'static'
            ? ($manifest['guessed'] ?? false ? 'A Vite app with no .onedrop/host.json, so a front end' : 'A front end').": serving {$manifest['static']}/ from Cloudflare."
            : 'An app with a server: running it on Fly.io.'.($manifest['services'] !== [] ? ' Services: '.implode(', ', $manifest['services']).'.' : ''));

        // Every account it needs, before anything is made.
        foreach ($this->kindsFor($deployment) as $kind) {
            $this->services->account($deployment->project, $kind);
        }

        if ($deployment->kind === 'server' && ($manifest['sqlite'] ?? []) !== [] && ! in_array(HostedServiceKind::Backup, $this->kindsFor($deployment), true)) {
            $deployment->note('No Cloudflare account to keep backups in, so its SQLite databases rely on the volume\'s daily snapshots.');
        }

        return $this->next($deployment, 'package');
    }

    /**
     * Build and pack it in the sandbox, which uploads each file straight to the release disk.
     *
     * @throws HostingException|SandboxException
     */
    protected function package(Deployment $deployment): int
    {
        $project = $deployment->project;
        $sandbox = $this->sandbox($project);
        $remote = '/tmp/onedrop-hosting-'.Str::lower(Str::random(8));
        $seed = $deployment->kind === 'server' && $this->needsSeed($deployment);
        // What it ships, so the Publish panel can tell what changed since (HOST-004).
        $deployment->update(['commit' => $this->changes->head($project, $sandbox)]);
        $deployment->note($seed ? 'Building and packing the app, with its data for the first deploy.' : 'Building and packing the app.');

        try {
            $packed = $this->run($sandbox, [self::SCRIPT, 'pack', $remote, ...($seed ? ['seed'] : [])], [], "Couldn't build the app");
            $files = [];

            foreach (preg_split('/\R/', trim($packed)) ?: [] as $line) {
                if (str_starts_with($line, 'note ')) {
                    $deployment->note(substr($line, 5));

                    continue;
                }

                [$name, $size] = explode(' ', $line) + [null, null];

                if (! in_array($name, ['release', 'static', 'seed', 'postgres'], true)) {
                    continue;
                }

                $file = $name === 'postgres' ? 'postgres.dump' : "{$name}.tar.gz";
                $path = $this->path($deployment, $file);
                ['url' => $url, 'headers' => $headers] = $this->disk($project)->temporaryUploadUrl($path, now()->addMinutes(30));

                $this->run($sandbox, [self::SCRIPT, 'put', "{$remote}/{$file}"], [
                    'ONEDROP_HOSTING_URL' => $url,
                    'ONEDROP_HOSTING_HEADERS' => $this->headerLines($headers),
                ], "Couldn't upload the {$name}");

                $files[$name] = $path;
                $deployment->note(sprintf('Uploaded the %s (%s).', $name, $this->size((int) $size)));
            }
        } finally {
            $this->sandboxes->exec($sandbox->external_id, ['rm', '-rf', $remote]);
        }

        if (! isset($files[$deployment->kind === 'static' ? 'static' : 'release'])) {
            throw new HostingException("Packing the app didn't make a release.");
        }

        $deployment->remember(['files' => $files]);

        return $this->next($deployment, 'provision');
    }

    /**
     * Make what the app is missing.
     *
     * @throws HostingException
     */
    protected function provision(Deployment $deployment): int
    {
        $project = $deployment->project;

        foreach ($this->kindsFor($deployment) as $kind) {
            if (! $project->hostedServices()->where('kind', $kind)->exists()) {
                $service = $this->services->ensure($project, $kind);
                $deployment->note(sprintf('Made the %s on %s%s.', strtolower($kind->label()), HostingProviders::PROVIDERS[$service->provider]['label'], $service->owner === HostedService::OWNER_ORGANIZATION ? " (your organization's account)" : ''));
                $deployment->remember(['made' => [...$deployment->remembered('made', []), $kind->value => true]]);
            }
        }

        return $this->next($deployment, $deployment->kind === 'static' ? 'upload' : 'build');
    }

    /**
     * Put a front end's files on its Worker.
     *
     * @throws HostingException
     */
    protected function upload(Deployment $deployment): ?int
    {
        $site = $this->services->ensure($deployment->project, HostedServiceKind::Site);
        $directory = storage_path('framework/hosting-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($directory);

        try {
            $archive = "{$directory}.tar.gz";
            $stream = $this->disk($deployment->project)->readStream($deployment->remembered('files.static'));

            if ($stream === null) {
                throw new HostingException("Couldn't read the packed site.");
            }

            file_put_contents($archive, $stream);
            $result = Process::run(['tar', '-xzf', $archive, '-C', $directory]);

            if ($result->failed()) {
                throw new HostingException("Couldn't unpack the site: {$result->errorOutput()}");
            }

            $deployment->note('Uploading the files to Cloudflare.');
            $url = (new CloudflareApi($this->services->accountFor($site)))->deploySite($site->name, $directory);
        } finally {
            File::deleteDirectory($directory);
            File::delete("{$directory}.tar.gz");
        }

        $this->finish($deployment, $url);

        return null;
    }

    /**
     * Start the builder: a machine in the app's own Fly account that adds the release to the base image and pushes it.
     *
     * @throws HostingException
     */
    protected function build(Deployment $deployment): int
    {
        $app = $this->services->ensure($deployment->project, HostedServiceKind::App);
        $account = $this->services->accountFor($app);
        $fly = new FlyApi($account);
        $image = FlyApi::REGISTRY."/{$app->name}:deployment-{$deployment->number}";
        $postgres = $deployment->project->hostedServices()->where('kind', HostedServiceKind::Postgres)->first();
        // Moving to Postgres, the hosted SQLite data goes in instead (HOST-009): the sandbox's test data mustn't.
        $restore = $postgres && $deployment->remembered('made.postgres') && $deployment->remembered('files.postgres') && $deployment->project->hosting_sqlite_import === null;

        $env = array_filter([
            'FLY_API_TOKEN' => $fly->token(),
            'ONEDROP_BASE_IMAGE' => (string) $account->get('base_image'),
            'ONEDROP_IMAGE' => $image,
            'ONEDROP_RELEASE_URL' => $this->link($deployment, $deployment->remembered('files.release')),
            'ONEDROP_CRANE_VERSION' => (string) config('hosting.crane_version'),
            'ONEDROP_POSTGRES_DUMP_URL' => $restore ? $this->link($deployment, $deployment->remembered('files.postgres')) : null,
            'ONEDROP_POSTGRES_URL' => $restore ? $postgres->details['url'] : null,
            'ONEDROP_LOG_URL' => URL::temporarySignedRoute('hosting.deployments.log', now()->addHours(2), ['deployment' => $deployment]),
        ]);

        $builder = $fly->createMachine($app->name, $app->region ?? (string) $account->get('region', 'iad'), [
            'image' => (string) $account->get('base_image'),
            'env' => $env,
            'init' => ['exec' => ['bash', '-c', self::builderScript()]],
            'guest' => ['cpu_kind' => 'shared', 'cpus' => 2, 'memory_mb' => 2048],
            'restart' => ['policy' => 'no'],
            'auto_destroy' => false,
            'metadata' => ['onedrop' => 'builder'],
        ], "builder-{$deployment->number}");

        $deployment->update(['image' => $image]);
        $deployment->remember(['builder' => $builder]);
        $deployment->note($restore ? "Building {$image}, and copying the sandbox's Postgres into the new database." : "Building {$image}.");

        return $this->next($deployment, 'building');
    }

    /**
     * Wait for the builder to exit.
     *
     * @throws HostingException
     */
    protected function building(Deployment $deployment): int
    {
        $app = $this->services->ensure($deployment->project, HostedServiceKind::App);
        $machine = (new FlyApi($this->services->accountFor($app)))->machine($app->name, (string) $deployment->remembered('builder'));

        if ($machine === null || in_array($machine['state'], ['stopped', 'destroyed', 'failed'], true)) {
            $this->removeBuilder($deployment);

            if (($machine['exit_code'] ?? null) !== 0) {
                throw new HostingException(sprintf('Building the image failed%s. See the log for details.', isset($machine['exit_code']) ? " (exit {$machine['exit_code']})" : ''));
            }

            $deployment->note('Built the image.');

            return $this->next($deployment, 'release');
        }

        return $this->wait($deployment, self::BUILD_POLLS, 'Building the image took too long.');
    }

    /**
     * Start the app's machine on the new image, or update the one it has.
     *
     * @throws HostingException
     */
    protected function release(Deployment $deployment): int
    {
        $project = $deployment->project;
        $app = $this->services->ensure($project, HostedServiceKind::App);
        $fly = new FlyApi($this->services->accountFor($app));
        $config = $this->appConfig($deployment, (string) $deployment->image);
        $machine = $app->details['machine'] ?? null;

        $deployment->remember(['previous' => $machine ? $this->previousImage($deployment) : null]);

        if ($machine && $fly->machine($app->name, $machine) !== null) {
            $fly->updateMachine($app->name, $machine, $config);
        } else {
            $machine = $fly->createMachine($app->name, $app->region ?? 'iad', $config, 'app');
            $app->update(['details' => [...($app->details ?? []), 'machine' => $machine]]);
        }

        $deployment->note('Starting the app.');

        return $this->next($deployment, 'verify');
    }

    /**
     * Wait for the app to answer at its address; put the last good image back if it never does.
     *
     * @throws HostingException
     */
    protected function verify(Deployment $deployment): ?int
    {
        $app = $this->services->ensure($deployment->project, HostedServiceKind::App);
        $url = "https://{$app->name}.fly.dev";
        $machine = (new FlyApi($this->services->accountFor($app)))->machine($app->name, (string) ($app->details['machine'] ?? ''));

        // Asking wakes a machine that already went to sleep, so an answer is enough; a failed one never will.
        $response = ($machine['state'] ?? null) !== 'failed' ? $this->ask($url) : null;

        if ($response !== null && ! in_array($response->status(), [502, 503, 504], true)) {
            $this->finish($deployment, $url);

            return null;
        }

        // The app keeps stopping as it starts (`hosting app` says so with its output): waiting longer won't help.
        if ($response?->header('X-OneDrop-App') === 'crashed') {
            $deployment->note("The app keeps stopping as it starts. Its last output:\n".trim($response->body()));
            $this->rollBack($deployment, $app);

            throw new HostingException('The app keeps stopping as it starts. Its output is in the deploy log.');
        }

        if ($deployment->polls >= self::VERIFY_POLLS) {
            $this->rollBack($deployment, $app);

            throw new HostingException("The app didn't start. Check that .onedrop/start (or .onedrop/dev) starts it on \$PORT.");
        }

        return $this->wait($deployment, self::VERIFY_POLLS, '');
    }

    /**
     * The app's machine: the image, its services' addresses, and the volume.
     *
     * @return array<string, mixed>
     */
    protected function appConfig(Deployment $deployment, string $image): array
    {
        $project = $deployment->project;
        $app = $project->hostedServices()->where('kind', HostedServiceKind::App)->firstOrFail();
        $volume = $project->hostedServices()->where('kind', HostedServiceKind::Volume)->first();
        $account = $this->services->accountFor($app);
        $seed = $volume && $deployment->remembered('made.volume') && $deployment->remembered('files.seed');
        $backup = $volume ? $project->hostedServices()->where('kind', HostedServiceKind::Backup)->first() : null;
        $sqlite = $deployment->remembered('manifest.sqlite', []);

        return [
            'image' => $image,
            'env' => array_filter([
                ...$this->services->environment($project),
                'ONEDROP_HOSTED' => '1',
                'ONEDROP_VOLUME' => $volume ? '1' : null,
                'ONEDROP_DATA' => $volume ? implode(':', $deployment->remembered('manifest.data', [])) : null,
                'ONEDROP_SEED_URL' => $seed ? $this->link($deployment, $deployment->remembered('files.seed')) : null,
                // Move to Postgres (HOST-009): the machine copies this SQLite file from the volume into Postgres, once.
                'ONEDROP_IMPORT_SQLITE' => $this->moving($deployment) ? $project->hosting_sqlite_import : null,
                // Litestream backs these up to the backups bucket, and restores them onto a new volume (HOST-008).
                ...($backup && $sqlite !== [] ? [
                    'ONEDROP_SQLITE' => implode(':', $sqlite),
                    'ONEDROP_BACKUP_BUCKET' => $backup->details['bucket'],
                    'ONEDROP_BACKUP_ENDPOINT' => $backup->details['endpoint'],
                    'LITESTREAM_ACCESS_KEY_ID' => $backup->details['access_key_id'],
                    'LITESTREAM_SECRET_ACCESS_KEY' => $backup->details['secret_access_key'],
                    'ONEDROP_LITESTREAM_VERSION' => (string) config('hosting.litestream_version'),
                ] : []),
            ], fn (?string $value) => $value !== null && $value !== ''),
            'services' => [[
                'protocol' => 'tcp',
                'internal_port' => self::PROXY_PORT,
                // Sleeps when nobody's visiting (Fly saves its memory) and wakes on the next request.
                'autostop' => 'suspend',
                'autostart' => true,
                'min_machines_running' => 0,
                'ports' => [
                    ['port' => 443, 'handlers' => ['tls', 'http']],
                    ['port' => 80, 'handlers' => ['http'], 'force_https' => true],
                ],
            ]],
            'mounts' => $volume ? [['volume' => $volume->external_id, 'path' => '/data']] : [],
            'guest' => MachineSizes::guest($project->hosting_size, $account),
            'restart' => ['policy' => 'always'],
            'metadata' => ['onedrop' => 'app', 'onedrop_deployment' => (string) $deployment->number],
        ];
    }

    /**
     * Put the last good image back after a release that never answered.
     */
    protected function rollBack(Deployment $deployment, HostedService $app): void
    {
        $previous = $deployment->remembered('previous');

        if (! $previous || ! isset($app->details['machine'])) {
            return;
        }

        try {
            // A version from before Move to Postgres goes back to its SQLite (HOST-009).
            $dropped = ['ONEDROP_SEED_URL' => true, ...($this->moving($deployment) ? ['DATABASE_URL' => true, 'DB_URL' => true, 'DB_CONNECTION' => true, 'ONEDROP_IMPORT_SQLITE' => true] : [])];

            (new FlyApi($this->services->accountFor($app)))->updateMachine($app->name, $app->details['machine'], [
                ...$this->appConfig($deployment, $previous),
                'env' => array_diff_key($this->appConfig($deployment, $previous)['env'], $dropped),
            ]);
            $deployment->note("Put the last good version back ({$previous}).");
        } catch (HostingException $e) {
            $deployment->note("Couldn't put the last good version back: {$e->getMessage()}");
        }
    }

    protected function previousImage(Deployment $deployment): ?string
    {
        return $deployment->project->deployments()
            ->where('status', DeploymentStatus::Live)
            ->where('kind', 'server')
            ->where('id', '<', $deployment->id)
            ->latest('id')
            ->value('image');
    }

    protected function finish(Deployment $deployment, string $url): void
    {
        // The machine only answers once the move to Postgres is done (HOST-009).
        if ($this->moving($deployment)) {
            $deployment->project->update(['hosting_sqlite_import' => null]);
            $deployment->note('Moved its SQLite data into Postgres. The SQLite file stays on the volume.');
        }

        $deployment->note("Live at {$url}.");
        $deployment->update(['status' => DeploymentStatus::Live, 'url' => $url, 'finished_at' => now()]);
        $this->cleanUp($deployment);

        try {
            $this->changes->refresh($deployment->project->fresh() ?? $deployment->project);
        } catch (SandboxException $e) {
            report($e);
        }
    }

    /**
     * The hosted app's main SQLite database, when it could move to Postgres (HOST-009): published to hosting, with its
     * data on a volume and no Postgres yet. Laravel's database/database.sqlite when it has one.
     */
    public function hostedSqlite(Project $project): ?string
    {
        $live = $this->changes->live($project);
        $sqlite = $live?->kind === 'server' ? $live->remembered('manifest.sqlite', []) : [];

        if ($sqlite === [] || $project->hostedServices()->where('kind', HostedServiceKind::Postgres)->exists()) {
            return null;
        }

        return in_array('database/database.sqlite', $sqlite, true) ? 'database/database.sqlite' : $sqlite[0];
    }

    /**
     * Whether this deploy moves the app's hosted SQLite data into Postgres (HOST-009): the user asked to, and the app
     * now has a Postgres to move it to and a volume it's on.
     */
    protected function moving(Deployment $deployment): bool
    {
        $project = $deployment->project;

        return $project->hosting_sqlite_import !== null
            && $deployment->kind === 'server'
            && $project->hostedServices()->whereIn('kind', [HostedServiceKind::Postgres, HostedServiceKind::Volume])->count() === 2;
    }

    /**
     * Put an earlier deployment's image back (HOST-005): a new deployment that skips straight to starting the app on
     * it, with its data left as it is. Only apps with a server keep their images.
     *
     * @throws HostingException
     */
    public function putBack(Project $project, Deployment $to, ?User $user): Deployment
    {
        if ($to->project_id !== $project->id || $to->kind !== 'server' || $to->status !== DeploymentStatus::Live || $to->image === null) {
            throw new HostingException('Only an earlier deploy of this app with a server can be put back.');
        }

        return $this->restart($project, $to, $user, "Putting deploy #{$to->number} back ({$to->image}).", ['rollback_of' => $to->number]);
    }

    /**
     * Restart the live version on the project's machine size (HOST-010): the same image, nothing built.
     *
     * @throws HostingException
     */
    public function resize(Project $project, ?User $user): Deployment
    {
        $live = $this->changes->live($project);

        if ($live?->kind !== 'server' || $live->image === null) {
            throw new HostingException('Only a hosted app with a server has a machine to resize.');
        }

        $label = MachineSizes::SIZES[$project->hosting_size ?? MachineSizes::DEFAULT]['label'];

        return $this->restart($project, $live, $user, "Moving to a new machine size ({$label}).", ['resized' => true]);
    }

    /**
     * A deployment that starts the app on an earlier deployment's image, skipping straight to releasing it.
     *
     * @param  array<string, mixed>  $state
     */
    protected function restart(Project $project, Deployment $to, ?User $user, string $note, array $state): Deployment
    {
        $deployment = DB::transaction(function () use ($project, $to, $user, $state) {
            $project->deployments()->where('status', DeploymentStatus::Running)->update([
                'status' => DeploymentStatus::Failed,
                'error' => 'A newer deploy replaced this one.',
                'finished_at' => now(),
            ]);

            return $project->deployments()->create([
                'user_id' => $user?->id,
                'number' => (int) $project->deployments()->max('number') + 1,
                'kind' => 'server',
                'status' => DeploymentStatus::Running,
                'step' => 'release',
                'image' => $to->image,
                'commit' => $to->commit,
                'state' => ['manifest' => $to->remembered('manifest', []), ...$state],
            ]);
        });

        $deployment->note($note);
        AdvanceDeployment::dispatch($deployment);

        return $deployment;
    }

    /**
     * The app's answer, or null when nothing answers yet. 502-504 are the gateway's own while the machine starts.
     */
    protected function ask(string $url): ?Response
    {
        try {
            return Http::timeout(10)->withoutRedirecting()->get($url);
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * Delete the release's files once nothing reads them; the seed waits until the volume has it (first boot).
     */
    protected function cleanUp(Deployment $deployment): void
    {
        $files = $deployment->remembered('files', []);

        if ($deployment->status === DeploymentStatus::Live) {
            unset($files['seed']);
        }

        if ($files !== []) {
            try {
                $this->disk($deployment->project)->delete(array_values($files));
            } catch (Throwable $e) {
                // A release left behind costs a little storage; it mustn't fail the deploy.
                report($e);
            }
        }
    }

    protected function removeBuilder(Deployment $deployment): void
    {
        $builder = $deployment->remembered('builder');
        $app = $deployment->project->hostedServices()->where('kind', HostedServiceKind::App)->first();

        if (! $builder || ! $app) {
            return;
        }

        try {
            (new FlyApi($this->services->accountFor($app)))->deleteMachine($app->name, $builder);
        } catch (HostingException $e) {
            report($e);
        }
    }

    /**
     * What the deployment needs made, in order.
     *
     * @return list<HostedServiceKind>
     */
    protected function kindsFor(Deployment $deployment): array
    {
        if ($deployment->kind === 'static') {
            return [HostedServiceKind::Site];
        }

        $manifest = $deployment->remembered('manifest', []);
        $services = $manifest['services'] ?? [];

        return array_values(array_filter([
            HostedServiceKind::App,
            ($manifest['data'] ?? []) !== [] || ($manifest['storage'] ?? false) ? HostedServiceKind::Volume : null,
            in_array('postgres', $services, true) ? HostedServiceKind::Postgres : null,
            in_array('redis', $services, true) ? HostedServiceKind::Redis : null,
            in_array('s3', $services, true) ? HostedServiceKind::Bucket : null,
            // Continuous SQLite backups (HOST-008), where there's a Cloudflare account to keep them in.
            ($manifest['sqlite'] ?? []) !== [] && $this->providers->account($deployment->project->organization, 'cloudflare') !== null
                ? HostedServiceKind::Backup : null,
        ]));
    }

    /**
     * Whether a data service it needs isn't made yet, so the sandbox's data goes along this once.
     */
    protected function needsSeed(Deployment $deployment): bool
    {
        $existing = $deployment->project->hostedServices()->pluck('kind')->map(fn (HostedServiceKind $kind) => $kind->value)->all();

        return collect($this->kindsFor($deployment))
            ->contains(fn (HostedServiceKind $kind) => in_array($kind, [HostedServiceKind::Volume, HostedServiceKind::Postgres], true) && ! in_array($kind->value, $existing, true));
    }

    protected function next(Deployment $deployment, string $step): int
    {
        $deployment->update(['step' => $step, 'polls' => 0]);

        return 0;
    }

    /**
     * @throws HostingException
     */
    protected function wait(Deployment $deployment, int $maxPolls, string $timeout): int
    {
        if ($deployment->polls >= $maxPolls) {
            throw new HostingException($timeout);
        }

        $deployment->increment('polls');

        return self::POLL_SECONDS;
    }

    /**
     * @throws HostingException
     */
    protected function sandbox(Project $project): Sandbox
    {
        $sandbox = $project->sandbox()->first();

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            throw new HostingException("The project's sandbox isn't running.");
        }

        if ($this->tools->available() && ! $this->tools->ensure($this->sandboxes, $sandbox->external_id, ['hosting'])) {
            throw new HostingException("Couldn't give the sandbox the hosting tool.");
        }

        return $sandbox;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     *
     * @throws SandboxException
     */
    protected function run(Sandbox $sandbox, array $command, array $env, string $failure): string
    {
        $result = $this->sandboxes->exec($sandbox->external_id, $command, $env);

        if (! $result->successful()) {
            throw new SandboxException("{$failure}: ".(trim($result->errorOutput ?: $result->output) ?: 'unknown error'));
        }

        return $result->output;
    }

    protected function link(Deployment $deployment, string $path): string
    {
        return $this->disk($deployment->project)->temporaryUrl($path, now()->addMinutes(self::LINK_MINUTES));
    }

    protected function path(Deployment $deployment, string $file): string
    {
        return "hosting/{$deployment->project_id}/{$deployment->number}/{$file}";
    }

    /**
     * @throws HostingException
     */
    protected function disk(Project $project): Filesystem
    {
        return $this->disks[$project->id] ??= $this->releases->disk($project);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    protected function headerLines(array $headers): string
    {
        return collect($headers)
            ->map(fn (string|array $value, string $name) => $name.': '.(is_array($value) ? implode(', ', $value) : $value))
            ->implode("\n");
    }

    protected function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    /**
     * What the builder machine runs: fetch crane, install the release's packages again when the sandbox that packed it
     * has another CPU (arm64 Docker on a Mac; native packages only run on their own), add the release to the base image (run as root, so the entrypoint
     * can put the volume's data in place before it starts the app as the sandbox user), push it, and on the first
     * deploy copy the sandbox's Postgres into the new database. Its log goes back to the deployment when this install
     * can be reached.
     */
    public static function builderScript(): string
    {
        return <<<'BASH'
            set -uo pipefail
            exec > >(tee /tmp/build.log) 2>&1
            report() {
                [ -n "${ONEDROP_LOG_URL:-}" ] && curl -fsS -m 20 -X POST --data-binary @/tmp/build.log -H 'Content-Type: text/plain' "$ONEDROP_LOG_URL" >/dev/null 2>&1 || true
            }
            trap report EXIT
            set -e
            cd /tmp
            echo "Fetching crane ${ONEDROP_CRANE_VERSION}."
            curl -fsSL "https://github.com/google/go-containerregistry/releases/download/${ONEDROP_CRANE_VERSION}/go-containerregistry_Linux_x86_64.tar.gz" | tar -xz crane
            ./crane auth login registry.fly.io -u x -p "$FLY_API_TOKEN"
            echo "Fetching the release."
            curl -fsS --retry 3 -o release.tar.gz "$ONEDROP_RELEASE_URL"
            arch="$(tar -xzOf release.tar.gz opt/onedrop-hosting/arch 2>/dev/null || true)"
            if [ -n "$arch" ] && [ "$arch" != "$(uname -m)" ]; then
                echo "Its packages were installed on ${arch}; installing them for $(uname -m)."
                mkdir release && tar -xzf release.tar.gz -C release
                bash release/opt/onedrop-hosting/run deps "$PWD/release/workspace"
                tar -czf release.tar.gz --numeric-owner -C release workspace opt
            fi
            echo "Adding it to ${ONEDROP_BASE_IMAGE}."
            ./crane append --platform linux/amd64 -b "$ONEDROP_BASE_IMAGE" -f release.tar.gz -t "$ONEDROP_IMAGE.tmp"
            ./crane mutate "$ONEDROP_IMAGE.tmp" --user root --entrypoint /opt/onedrop-hosting/run --cmd run -t "$ONEDROP_IMAGE"
            echo "Pushed ${ONEDROP_IMAGE}."
            if [ -n "${ONEDROP_POSTGRES_DUMP_URL:-}" ]; then
                echo "Copying the sandbox's Postgres."
                curl -fsS --retry 3 -o postgres.dump "$ONEDROP_POSTGRES_DUMP_URL"
                pg_restore --no-owner --no-acl -d "$ONEDROP_POSTGRES_URL" postgres.dump || echo "pg_restore reported problems (above); the app may still work."
            fi
            BASH;
    }
}
