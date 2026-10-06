<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * What runs in a project's sandbox, for the Services tab (SVC-001): the preview server, the containers its own
 * Docker runs (SBX-008), and the ports open in it. The script runs inside the sandbox with its own PHP, so
 * sandboxes made before this work without an update.
 */
class SandboxServices
{
    /** Container actions a user can take. */
    public const ACTIONS = ['restart', 'stop', 'start'];

    /** Lines of a container's log shown at once. */
    public const LOG_LINES = 200;

    /** The most log lines that can be asked for at once (the expanded log view). */
    public const MAX_LOG_LINES = 2000;

    /**
     * The preview server's command (.onedrop/dev without comments) and whether it's running, and every container
     * Docker has, if the sandbox has a Docker that answers. Prints JSON: {dev, running, docker, containers}.
     */
    protected const SCRIPT = <<<'PHP'
        $dev = '/workspace/.onedrop/dev';
        $command = null;
        if (is_file($dev)) {
            $lines = array_values(array_filter(array_map('trim', @file($dev) ?: []), fn ($line) => $line !== '' && $line[0] !== '#'));
            $command = implode("\n", array_slice($lines, 0, 8));
        }
        $pid = (int) @file_get_contents('/tmp/onedrop-server.pid');
        $containers = null;
        if (file_exists('/var/run/docker.sock')) {
            exec("docker ps --all --no-trunc --format '{{json .}}' 2>/dev/null", $out, $code);
            if ($code === 0) {
                $containers = array_values(array_filter(array_map(fn ($line) => json_decode($line, true), $out), 'is_array'));
            }
        }
        echo json_encode([
            'dev' => $command,
            'running' => $pid > 0 && file_exists("/proc/{$pid}"),
            'docker' => file_exists('/var/run/docker.sock'),
            'containers' => $containers,
        ]);
        PHP;

    public function __construct(protected SandboxProvider $provider, protected SandboxInspector $inspector) {}

    /**
     * Everything the Services tab shows.
     *
     * @return array{
     *     preview: array{command: string|null, running: bool, port: int},
     *     docker: 'off'|'down'|'up',
     *     containers: list<array{name: string, project: string|null, service: string|null, image: string, state: string, status: string, exit_code: int|null, ports: list<array{published: int, target: int}>}>,
     *     ports: list<array{address: string, port: int, pid: int|null, process: string|null, role: string|null, service: string|null}>,
     * }
     *
     * @throws SandboxException
     */
    public function describe(Sandbox $sandbox): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['php', '-r', self::SCRIPT]);
        $data = json_decode($result->output, true);

        if (! $result->successful() || ! is_array($data)) {
            throw new SandboxException(__("Couldn't read what's running in the sandbox."));
        }

        $containers = array_map(fn (array $container) => $this->container($container), is_array($data['containers'] ?? null) ? $data['containers'] : []);
        usort($containers, fn (array $a, array $b) => [$a['project'] ?? '', $a['service'] ?? $a['name']] <=> [$b['project'] ?? '', $b['service'] ?? $b['name']]);

        // A port Docker publishes belongs to that container's service.
        $published = [];
        foreach ($containers as $container) {
            foreach ($container['ports'] as $port) {
                $published[$port['published']] = $container['service'] ?? $container['name'];
            }
        }

        $ports = array_map(fn (array $port) => [...$port, 'service' => $published[$port['port']] ?? null], $this->inspector->ports($sandbox));

        return [
            'preview' => [
                'command' => is_string($data['dev'] ?? null) ? $data['dev'] : null,
                'running' => (bool) ($data['running'] ?? false),
                'port' => (int) config('sandbox.port'),
            ],
            'docker' => match (true) {
                ! ($data['docker'] ?? false) => 'off',
                ! is_array($data['containers'] ?? null) => 'down',
                default => 'up',
            },
            'containers' => $containers,
            'ports' => $ports,
        ];
    }

    /**
     * A container's latest log lines, stdout and stderr together.
     *
     * @throws SandboxException
     */
    public function logs(Sandbox $sandbox, string $name, int $lines = self::LOG_LINES): string
    {
        $this->ensureContainer($sandbox, $name);

        $result = $this->provider->exec($sandbox->external_id, ['bash', '-c', 'docker logs --tail "$1" "$2" 2>&1', 'logs', (string) $lines, $name]);

        if (! $result->successful()) {
            throw new SandboxException(__("Couldn't read the logs of :name.", ['name' => $name]));
        }

        return $result->output;
    }

    /**
     * Every given container's latest log lines merged in time order, each line as "label<TAB>line". Docker's
     * timestamps (fixed-width RFC 3339 in UTC) sort as text, so a stable sort on them keeps each container's own
     * order. Arguments: lines, then name and label pairs.
     */
    public const MERGED_LOGS_SCRIPT = <<<'BASH'
        lines="$1"; shift
        while [ "$#" -gt 1 ]; do
            docker logs --timestamps --tail "$lines" "$1" 2>&1 \
                | LABEL="$2" awk '{ time = $1; sub(/^[^ ]* ?/, ""); print time "\t" ENVIRON["LABEL"] "\t" $0 }'
            shift 2
        done | LC_ALL=C sort -s -t "$(printf '\t')" -k1,1 | cut -f2- | tail -n "$lines"
        BASH;

    /**
     * The latest log lines of every container, merged in time order, each as "label<TAB>line" where the label is
     * its compose service (its name when two projects share a service name, or it has none).
     *
     * @throws SandboxException
     */
    public function mergedLogs(Sandbox $sandbox, int $lines = self::LOG_LINES): string
    {
        $containers = $this->describe($sandbox)['containers'];

        if ($containers === []) {
            return '';
        }

        $services = array_count_values(array_filter(array_column($containers, 'service')));
        $arguments = [];
        foreach ($containers as $container) {
            $service = $container['service'];
            $arguments[] = $container['name'];
            $arguments[] = $service !== null && $services[$service] === 1 ? $service : $container['name'];
        }

        $result = $this->provider->exec($sandbox->external_id, ['bash', '-c', self::MERGED_LOGS_SCRIPT, 'logs', (string) $lines, ...$arguments]);

        if (! $result->successful()) {
            throw new SandboxException(__("Couldn't read the containers' logs."));
        }

        return $result->output;
    }

    /**
     * Restarts a container, but recreates a compose service whose config (its compose files, .env) changed since it
     * was made, which `docker restart` would ignore. An unchanged one is only restarted, so it keeps what it wrote
     * outside its volumes.
     */
    protected const RESTART_SCRIPT = <<<'BASH'
        label() { docker inspect --format "{{index .Config.Labels \"$1\"}}" "$name"; }
        service="$(label com.docker.compose.service)"
        if [ -z "$service" ]; then
            exec docker restart "$name"
        fi
        cd "$(label com.docker.compose.project.working_dir)" || exit 1
        files=()
        IFS=, read -ra paths <<<"$(label com.docker.compose.project.config_files)"
        for path in "${paths[@]}"; do files+=(-f "$path"); done
        compose=(docker compose --ansi never -p "$(label com.docker.compose.project)" "${files[@]}")
        hash="$("${compose[@]}" config --hash "$service" | cut -d' ' -f2)"
        if [ -n "$hash" ] && [ "$hash" = "$(label com.docker.compose.config-hash)" ]; then
            exec docker restart "$name"
        fi
        exec "${compose[@]}" up --detach --no-deps "$service"
        BASH;

    /**
     * Restart, stop or start a container, in the background: stopping can take Docker several seconds.
     *
     * @throws SandboxException
     */
    public function act(Sandbox $sandbox, string $name, string $action): void
    {
        $this->ensureContainer($sandbox, $name);

        $command = $action === 'restart'
            ? ['bash', '-c', 'name="$1"; '.self::RESTART_SCRIPT, 'restart', $name]
            : ['docker', $action, $name];

        $this->provider->exec($sandbox->external_id, $command, detach: true);
    }

    /**
     * Restart the preview server; its loop starts .onedrop/dev (or the placeholder) again.
     *
     * @throws SandboxException
     */
    public function restartPreview(Sandbox $sandbox): void
    {
        $result = $this->provider->exec($sandbox->external_id, ['/opt/onedrop/restart']);

        if (! $result->successful()) {
            throw new SandboxException(__("Couldn't restart the preview."));
        }
    }

    /**
     * Only containers the sandbox's Docker has can be acted on, so a name can never be read as an option.
     *
     * @throws SandboxException
     */
    protected function ensureContainer(Sandbox $sandbox, string $name): void
    {
        $names = array_column($this->describe($sandbox)['containers'], 'name');

        if (! in_array($name, $names, true)) {
            throw new SandboxException(__('There is no container called :name in this sandbox.', ['name' => $name]));
        }
    }

    /**
     * One line of `docker ps --format '{{json .}}'`, with its compose project and service from its labels, its exit
     * code when it has one ("Exited (0) …", "Restarting (127) …"), and the ports it publishes.
     *
     * @param  array<string, mixed>  $container
     * @return array{name: string, project: string|null, service: string|null, image: string, state: string, status: string, exit_code: int|null, ports: list<array{published: int, target: int}>}
     */
    protected function container(array $container): array
    {
        $labels = [];
        foreach (explode(',', (string) ($container['Labels'] ?? '')) as $label) {
            [$key, $value] = array_pad(explode('=', $label, 2), 2, '');
            $labels[$key] = $value;
        }

        $status = (string) ($container['Status'] ?? '');
        preg_match_all('/:(\d+)->(\d+)\//', (string) ($container['Ports'] ?? ''), $matches, PREG_SET_ORDER);
        $ports = [];
        foreach ($matches as [, $published, $target]) {
            $ports[(int) $published] = ['published' => (int) $published, 'target' => (int) $target];
        }

        return [
            'name' => (string) ($container['Names'] ?? ''),
            'project' => filled($labels['com.docker.compose.project'] ?? null) ? $labels['com.docker.compose.project'] : null,
            'service' => filled($labels['com.docker.compose.service'] ?? null) ? $labels['com.docker.compose.service'] : null,
            'image' => (string) ($container['Image'] ?? ''),
            'state' => (string) ($container['State'] ?? ''),
            'status' => $status,
            'exit_code' => preg_match('/^(?:Exited|Restarting) \((\d+)\)/', $status, $code) ? (int) $code[1] : null,
            'ports' => array_values($ports),
        ];
    }
}
