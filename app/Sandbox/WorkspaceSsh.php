<?php

namespace App\Sandbox;

use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SshKey;

/**
 * SSH access to a sandbox (Tools → Developer → SSH). The sandbox runs sshd as its own user
 * (docker/sandbox/start.sh), key-only; the project owner's public keys are its authorized_keys.
 */
class WorkspaceSsh
{
    public const USER = 'sandbox';

    public const AUTHORIZED_KEYS = '/home/sandbox/.ssh/authorized_keys';

    /** Exit status of the sync command when the image has no SSH server. */
    public const NO_SERVER = 3;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Replace the sandbox's authorized keys with the project owner's. Returns false when the
     * sandbox's image has no SSH server (built before SSH was added).
     *
     * @throws SandboxException
     */
    public function sync(Sandbox $sandbox): bool
    {
        $keys = $sandbox->project->user->sshKeys->sortBy('id')
            ->map(fn (SshKey $key) => $key->public_key.' '.str($key->name)->replaceMatches('/\s+/', '-'))
            ->implode("\n");

        $result = $this->provider->exec($sandbox->external_id, ['sh', '-c', implode(' && ', [
            'test -x /usr/sbin/sshd || exit '.self::NO_SERVER,
            'mkdir -p ~/.ssh',
            'chmod 700 ~/.ssh',
            'printf "%s\n" "$APP_SSH_KEYS" > '.self::AUTHORIZED_KEYS.'.tmp',
            'chmod 600 '.self::AUTHORIZED_KEYS.'.tmp',
            'mv '.self::AUTHORIZED_KEYS.'.tmp '.self::AUTHORIZED_KEYS,
        ])], ['APP_SSH_KEYS' => $keys]);

        if ($result->exitCode === self::NO_SERVER) {
            return false;
        }

        if (! $result->successful()) {
            throw new SandboxException(__("Couldn't update the sandbox's SSH keys."));
        }

        return true;
    }

    /**
     * The name the project goes by in ~/.ssh/config and editor links.
     */
    public static function hostAlias(Project $project): string
    {
        return 'onedrop-'.$project->publishHostname();
    }

    /**
     * Split a stored "host:port" address.
     *
     * @return array{host: string, port: int}|null
     */
    public static function address(?string $address): ?array
    {
        if ($address === null || ! preg_match('/^(.+):(\d+)$/', $address, $matches)) {
            return null;
        }

        return ['host' => $matches[1], 'port' => (int) $matches[2]];
    }
}
