<?php

namespace App\Sandbox\Providers;

use App\Models\Sandbox;
use App\Models\SandboxMove;
use App\Sandbox\ExecResult;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use Closure;

/**
 * Drives each sandbox with the provider it was created on (its `provider` column), and creates new ones on the
 * configured provider, so switching SANDBOX_PROVIDER never breaks existing projects and several providers can
 * run side by side. SandboxUpdater moves projects to the configured provider.
 */
class RoutingSandboxProvider implements SandboxProvider
{
    /** @var array<string, SandboxProvider> */
    protected array $providers = [];

    /** @var array<string, string> external id => provider name, remembered so a sandbox is found after its record moves on */
    protected array $owners = [];

    /**
     * @param  array<string, Closure(): SandboxProvider>  $factories  provider name => how to make it
     */
    public function __construct(protected array $factories, protected string $default) {}

    /**
     * The provider with this name.
     *
     * @throws SandboxException
     */
    public function provider(string $name): SandboxProvider
    {
        if (! isset($this->factories[$name])) {
            throw new SandboxException("Sandbox provider [{$name}] isn't available. Use one of: ".implode(', ', array_keys($this->factories)).'.');
        }

        return $this->providers[$name] ??= ($this->factories[$name])();
    }

    public function create(SandboxSpec $spec): string
    {
        // A project moved to a computer runs there (DESK-010), whatever the install's provider.
        $name = $spec->deviceId !== null ? 'device' : $this->default;
        $id = $this->provider($name)->create($spec);
        $this->owners[$id] = $name;

        return $id;
    }

    public function start(string $id): void
    {
        $this->for($id)->start($id);
    }

    public function pause(string $id): void
    {
        $this->for($id)->pause($id);
    }

    public function suspend(string $id): void
    {
        $this->for($id)->suspend($id);
    }

    public function wake(string $id): bool
    {
        return $this->for($id)->wake($id);
    }

    public function exec(string $id, array $command, array $env = [], bool $detach = false, bool $root = false): ExecResult
    {
        return $this->for($id)->exec($id, $command, $env, $detach, $root);
    }

    public function previewUrl(string $id, int $port): ?string
    {
        return $this->for($id)->previewUrl($id, $port);
    }

    public function isOutdated(string $id): bool
    {
        return $this->for($id)->isOutdated($id);
    }

    public function copyOut(string $id, string $path, string $directory): void
    {
        $this->for($id)->copyOut($id, $path, $directory);
    }

    public function copyIn(string $id, string $directory, string $path): void
    {
        $this->for($id)->copyIn($id, $directory, $path);
    }

    public function installFiles(string $id, string $directory, string $path): bool
    {
        return $this->for($id)->installFiles($id, $directory, $path);
    }

    /**
     * Whether new sandboxes can be made on the configured provider.
     */
    public function checkImage(): void
    {
        $this->provider($this->default)->checkImage();
    }

    public function destroy(string $id): void
    {
        $this->for($id)->destroy($id);
    }

    /**
     * The provider that owns a sandbox: the one that created it here, else its record's or its move's, else the configured one.
     *
     * @throws SandboxException
     */
    protected function for(string $id): SandboxProvider
    {
        // A sandbox a move is making, or one a project moved off (kept while it may hold newer files, SBX-013), has no
        // record of its own; its move knows where it is.
        $this->owners[$id] ??= Sandbox::query()->where('external_id', $id)->value('provider')
            ?? SandboxMove::query()->where('to_external_id', $id)->latest('id')->value('to_provider')
            ?? SandboxMove::query()->where('from_external_id', $id)->latest('id')->value('from_provider')
            ?? $this->default;

        return $this->provider($this->owners[$id]);
    }
}
