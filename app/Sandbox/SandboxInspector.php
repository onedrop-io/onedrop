<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * Live details about a sandbox for Tools → Developer: the ports open inside it, its CPU and memory
 * use against its limits, and its disk use. The scripts run inside the sandbox with its own PHP,
 * so older images work without a rebuild.
 */
class SandboxInspector
{
    /**
     * Listening TCP sockets from /proc/net, with the process that owns each (when it's in the sandbox).
     * Prints JSON: [{address, port, pid, process}].
     */
    protected const PORTS_SCRIPT = <<<'PHP'
        $owners = [];
        foreach (glob('/proc/[0-9]*/fd/*') ?: [] as $fd) {
            if (preg_match('/^socket:\[(\d+)\]$/', (string) @readlink($fd), $m)) {
                $owners[$m[1]] ??= (int) explode('/', $fd)[2];
            }
        }
        $ports = [];
        foreach (['/proc/net/tcp' => 4, '/proc/net/tcp6' => 6] as $file => $family) {
            foreach (array_slice(@file($file) ?: [], 1) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (($cols[3] ?? '') !== '0A') {
                    continue;
                }
                [$hex, $port] = explode(':', $cols[1]);
                $raw = implode('', array_map('strrev', str_split(hex2bin($hex), 4)));
                $address = inet_ntop($raw);
                $pid = $owners[$cols[9] ?? ''] ?? null;
                $ports[$address.':'.hexdec($port)] = [
                    'address' => $address,
                    'port' => hexdec($port),
                    'pid' => $pid,
                    'process' => $pid ? trim((string) @file_get_contents("/proc/$pid/comm")) : null,
                ];
            }
        }
        echo json_encode(array_values($ports));
        PHP;

    /**
     * CPU (sampled over half a second) and memory from the sandbox's cgroup, with its limits.
     * Prints JSON: {cpus, cpu, user, system, memory_limit, memory, active, cache} (null where unknown).
     */
    protected const USAGE_SCRIPT = <<<'PHP'
        $read = fn ($name) => @file_get_contents("/sys/fs/cgroup/$name");
        $stat = function ($name) use ($read) {
            preg_match_all('/^(\w+) (\d+)$/m', (string) $read($name), $m);
            return array_map('intval', array_combine($m[1], $m[2]));
        };
        $meminfo = (string) @file_get_contents('/proc/meminfo');
        $total = preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m) ? (int) $m[1] * 1024 : null;
        [$quota, $period] = explode(' ', trim($read('cpu.max') ?: 'max 100000')) + [1 => 100000];
        $cpus = $quota === 'max' ? (int) trim((string) shell_exec('nproc')) : (int) $quota / (int) $period;
        $before = $stat('cpu.stat');
        $start = hrtime(true);
        usleep(500000);
        $after = $stat('cpu.stat');
        $elapsed = (hrtime(true) - $start) / 1000;
        $share = fn ($key) => isset($before[$key], $after[$key]) && $cpus > 0
            ? min(1, max(0, ($after[$key] - $before[$key]) / ($elapsed * $cpus))) : null;
        $memory = $read('memory.current');
        $max = trim((string) $read('memory.max'));
        $mem = $stat('memory.stat');
        echo json_encode([
            'cpus' => $cpus ?: null,
            'cpu' => $share('usage_usec'),
            'user' => $share('user_usec'),
            'system' => $share('system_usec'),
            'memory_limit' => ctype_digit($max) ? (int) $max : $total,
            'memory' => $memory === false ? null : (int) $memory,
            'active' => $mem['anon'] ?? null,
            'cache' => $mem['file'] ?? null,
        ]);
        PHP;

    /**
     * Disk use of the workspace, its dependencies, App Storage, and the whole disk.
     * Prints JSON: {workspace, dependencies, storage, disk_total, disk_free} in bytes (null where unknown).
     */
    protected const STORAGE_SCRIPT = <<<'PHP'
        $du = function (array $paths) {
            $paths = array_filter($paths, 'is_dir');
            if ($paths === []) {
                return 0;
            }
            $out = (string) shell_exec('timeout 20 du -skc '.implode(' ', array_map('escapeshellarg', $paths)).' 2>/dev/null | tail -n 1');
            return preg_match('/^(\d+)/', $out, $m) ? (int) $m[1] * 1024 : null;
        };
        $deps = array_filter(explode("\n", (string) shell_exec(
            'timeout 10 find /workspace -maxdepth 4 -type d \( -name node_modules -o -name vendor \) -prune -print 2>/dev/null'
        )));
        echo json_encode([
            'workspace' => $du(['/workspace']),
            'dependencies' => $du($deps),
            'storage' => $du([getenv('APP_STORAGE_DIR') ?: '/data/storage']),
            'disk_total' => @disk_total_space('/workspace') ?: null,
            'disk_free' => @disk_free_space('/workspace') ?: null,
        ]);
        PHP;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Ports listening inside the sandbox, lowest first, each labelled with what uses it (null when nothing we know of).
     *
     * @return list<array{address: string, port: int, pid: int|null, process: string|null, role: string|null}>
     *
     * @throws SandboxException
     */
    public function ports(Sandbox $sandbox): array
    {
        $roles = [
            config('sandbox.port') => 'app',
            config('sandbox.proxy_port') => 'proxy',
            config('sandbox.shell_port') => 'shell',
            config('sandbox.ssh_port') => 'ssh',
        ];

        $ports = array_map(fn (array $port) => [
            'address' => (string) $port['address'],
            'port' => (int) $port['port'],
            'pid' => isset($port['pid']) ? (int) $port['pid'] : null,
            'process' => filled($port['process'] ?? null) ? (string) $port['process'] : null,
            'role' => $roles[(int) $port['port']] ?? null,
        ], array_filter($this->run($sandbox, self::PORTS_SCRIPT), 'is_array'));

        usort($ports, fn (array $a, array $b) => [$a['port'], $a['address']] <=> [$b['port'], $b['address']]);

        return array_slice($ports, 0, 100);
    }

    /**
     * CPU use as a share (0–1) of the sandbox's CPU limit, and memory use in bytes.
     *
     * @return array{cpus: float|null, cpu: float|null, user: float|null, system: float|null, memory_limit: int|null, memory: int|null, active: int|null, cache: int|null}
     *
     * @throws SandboxException
     */
    public function usage(Sandbox $sandbox): array
    {
        $usage = $this->run($sandbox, self::USAGE_SCRIPT);
        $float = fn (string $key) => is_numeric($usage[$key] ?? null) ? round((float) $usage[$key], 4) : null;
        $int = fn (string $key) => is_numeric($usage[$key] ?? null) ? (int) $usage[$key] : null;

        return [
            'cpus' => $float('cpus'),
            'cpu' => $float('cpu'),
            'user' => $float('user'),
            'system' => $float('system'),
            'memory_limit' => $int('memory_limit'),
            'memory' => $int('memory'),
            'active' => $int('active'),
            'cache' => $int('cache'),
        ];
    }

    /**
     * Disk use in bytes.
     *
     * @return array{workspace: int|null, dependencies: int|null, storage: int|null, disk_total: int|null, disk_free: int|null}
     *
     * @throws SandboxException
     */
    public function storage(Sandbox $sandbox): array
    {
        $storage = $this->run($sandbox, self::STORAGE_SCRIPT);

        return array_map(
            fn (string $key) => is_numeric($storage[$key] ?? null) ? (int) $storage[$key] : null,
            array_combine($keys = ['workspace', 'dependencies', 'storage', 'disk_total', 'disk_free'], $keys),
        );
    }

    /**
     * Run a script inside the sandbox and decode the JSON it prints.
     *
     * @return array<array-key, mixed>
     *
     * @throws SandboxException
     */
    protected function run(Sandbox $sandbox, string $script): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['php', '-r', $script]);
        $data = json_decode($result->output, true);

        if (! $result->successful() || ! is_array($data)) {
            throw new SandboxException(__("Couldn't read the sandbox's details."));
        }

        return $data;
    }
}
