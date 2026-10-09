<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Enums\SocialProvider;
use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Puts an organization's secrets (SECRET-003) in its projects' sandboxes, as a file the Shell, the app and the agent
 * read their environment from (docker/sandbox/org-secrets, forwarder.mjs). It's kept outside /workspace and the
 * sandbox user's home, so it never goes into the project's files, a hosted release, a snapshot or a remix.
 *
 * The project owner's GitHub token travels with them as GITHUB_TOKEN (GIT-016), and Docker and npm are pointed at it
 * for GitHub's registries: Docker through a credential helper (docker/sandbox/lazy/docker-credential-onedrop), npm
 * through `${GITHUB_TOKEN}` in its user config, so neither file in the home folder holds the token itself.
 */
class OrganizationSecrets
{
    /** One `NAME base64(value)` line per secret. */
    public const FILE = '/tmp/onedrop-org-secrets';

    /** The name the project owner's GitHub token has in the sandbox, as in a codespace (GIT-016). */
    public const GITHUB_TOKEN = 'GITHUB_TOKEN';

    /** The tool files that read the file, which only need bash: copied into a sandbox that doesn't have them yet. */
    public const READERS = ['org-secrets', 'bashrc', 'lazy/docker-credential-onedrop'];

    /** Writes the file only when it changed (an empty one is left out), and says so, for a restart; then points Docker and npm at GITHUB_TOKEN while it has one. */
    protected const WRITE = <<<'SH'
        umask 077
        new="$1.$$"
        printf '%s' "$ONEDROP_ORG_SECRETS" >"$new"
        if [ ! -s "$new" ] && [ ! -e "$1" ] || cmp -s "$new" "$1"; then
            rm -f "$new"
        else
            mv -f "$new" "$1" && echo changed || exit 1
        fi

        # GitHub's registries use GITHUB_TOKEN while there is one (GIT-016), and stop when it's gone.
        docker="$HOME/.docker/config.json" npmrc="$HOME/.npmrc" line='//npm.pkg.github.com/:_authToken=${GITHUB_TOKEN}'
        if grep -q '^GITHUB_TOKEN ' "$1" 2>/dev/null; then
            touch "$npmrc" && { grep -qxF "$line" "$npmrc" || printf '%s\n' "$line" >>"$npmrc"; }
            mkdir -p "${docker%/*}" && { [ -s "$docker" ] || echo '{}' >"$docker"; }
            jq '.credHelpers["ghcr.io"] = "onedrop"' "$docker" >"$docker.$$" && mv -f "$docker.$$" "$docker"
        else
            [ ! -f "$npmrc" ] || { grep -vxF "$line" "$npmrc" >"$npmrc.$$"; mv -f "$npmrc.$$" "$npmrc"; }
            [ ! -s "$docker" ] || { jq 'if .credHelpers["ghcr.io"] == "onedrop" then del(.credHelpers["ghcr.io"]) else . end' "$docker" >"$docker.$$" && mv -f "$docker.$$" "$docker"; }
        fi
        true
        SH;

    public function __construct(protected SandboxProvider $provider, protected SandboxTools $tools) {}

    /**
     * The secrets that reach a project, by name, with its owner's GitHub token unless a secret has its name.
     *
     * @return array<string, string>
     */
    public function for(Project $project): array
    {
        $secrets = OrganizationSecret::query()->reaching($project)->orderBy('name')->get()
            ->mapWithKeys(fn (OrganizationSecret $secret) => [$secret->name => $secret->value])
            ->all();

        $token = $this->ownerGitHubToken($project);

        return $token === null ? $secrets : $secrets + [self::GITHUB_TOKEN => $token];
    }

    /**
     * The project owner's GitHub token, when it can download packages and they're still in its organization (GIT-016).
     */
    public function ownerGitHubToken(Project $project): ?string
    {
        $owner = $project->user;

        if ($owner === null || $project->isComputer() || ! $owner->belongsToOrganization($project->organization_id)) {
            return null;
        }

        $account = $owner->socialAccounts()->where('provider', SocialProvider::GitHub)->first();

        return $account?->canReadPackages() ? $account->token : null;
    }

    /**
     * The file's contents for the given secrets.
     *
     * @param  array<string, string>  $secrets
     */
    public static function contents(array $secrets): string
    {
        return implode('', array_map(fn (string $name, string $value) => $name.' '.base64_encode($value)."\n", array_keys($secrets), $secrets));
    }

    /**
     * Bring a sandbox's copy up to date, and restart its app when it changed so the app has them too. What each
     * sandbox was last given is remembered, so a sandbox that's up to date (or never had any) isn't asked again.
     *
     * @throws SandboxException
     */
    public function sync(Sandbox $sandbox): void
    {
        if ($sandbox->external_id === null || $sandbox->project->isComputer()) {
            return;
        }

        $contents = self::contents($this->for($sandbox->project));
        $key = "org-secrets:{$sandbox->external_id}";

        if (Cache::get($key, sha1('')) === sha1($contents)) {
            return;
        }

        if ($contents !== '') {
            // What reads them in the Shell, now rather than when the sandbox's tools are next brought up to date.
            $this->tools->ensure($this->provider, $sandbox->external_id, self::READERS);
        }

        $result = $this->provider->exec($sandbox->external_id, ['bash', '-c', self::WRITE, 'org-secrets', self::FILE], [
            'ONEDROP_ORG_SECRETS' => $contents,
        ]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't update the organization's secrets in the sandbox: ".(strtok(trim($result->errorOutput), "\n") ?: 'unknown error'));
        }

        Cache::put($key, sha1($contents), now()->addDays(30));

        if (trim($result->output) === 'changed') {
            $this->provider->exec($sandbox->external_id, ['/opt/onedrop/restart']);
        }
    }

    /**
     * After a change, update the organization's running sandboxes, or those of a person's projects (task copies too).
     * Suspended ones aren't woken for it: they're brought up to date when they wake (Sandbox::wake()), and every run
     * updates its own first.
     */
    public function syncAwake(Organization|User $for): void
    {
        Sandbox::query()
            ->where('status', SandboxStatus::Running)
            ->whereNull('suspended_at')
            ->whereNotNull('external_id')
            ->whereHas('project', fn ($query) => $for instanceof User
                ? $query->where('user_id', $for->id)
                : $query->where('organization_id', $for->id))
            ->with('project')
            ->each(function (Sandbox $sandbox) {
                try {
                    $this->sync($sandbox);
                } catch (SandboxException $e) {
                    report($e);
                }
            });
    }
}
