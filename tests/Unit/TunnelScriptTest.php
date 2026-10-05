<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

/** A port nothing listens on right now. */
function tunnelFreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $port;
}

/** A ticket signed as the app signs them (docs/development/desktop-link.mdx). */
function tunnelTicket(string $key, array $payload): string
{
    $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

    return $body.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
}

/** Wait until a local HTTP address answers. */
function tunnelWaitFor(string $url): void
{
    for ($i = 0; $i < 100; $i++) {
        if (@file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]])) !== false) {
            return;
        }

        usleep(50_000);
    }

    throw new RuntimeException("{$url} never answered.");
}

/**
 * Run a scenario in Node against the tunnel, as the desktop app would (Node's own WebSocket client), and return what
 * it reports with done(). `args` and `url` (the tunnel's WebSocket address) are in scope, with helpers to open
 * WebSockets, read their messages, run TCP servers and play the app's side of dials.
 */
function tunnelScenario(string $url, array $args, string $script): array
{
    $prelude = <<<'JS'
        import net from 'node:net';
        import http from 'node:http';
        import { readFileSync } from 'node:fs';

        const args = JSON.parse(process.env.ARGS);
        const url = process.env.TUNNEL_URL;
        const done = (result) => { console.log(JSON.stringify(result)); process.exit(0); };
        setTimeout(() => { console.error('timed out'); process.exit(1); }, 15000);
        const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

        /** A WebSocket to the tunnel with this query, or null when it's refused. */
        function open(query, base = url) {
            return new Promise((resolve) => {
                const ws = new WebSocket(`${base}?${query}`);
                ws.binaryType = 'arraybuffer';
                ws.queue = [];
                ws.waiters = [];
                ws.onmessage = (event) => {
                    const data = typeof event.data === 'string' ? event.data : Buffer.from(event.data);
                    const waiter = ws.waiters.shift();
                    waiter ? waiter(data) : ws.queue.push(data);
                };
                ws.closed = new Promise((closed) => {
                    ws.onclose = (event) => { closed({ code: event.code, reason: event.reason }); resolve(null); };
                    // Node 22 fires no close after a refused handshake, only an error.
                    ws.onerror = () => { if (ws.readyState !== WebSocket.OPEN) { closed({ code: 1006, reason: '' }); resolve(null); } };
                });
                ws.onopen = () => resolve(ws);
            });
        }

        function next(ws) {
            return ws.queue.length ? Promise.resolve(ws.queue.shift()) : new Promise((resolve) => ws.waiters.push(resolve));
        }

        async function nextJson(ws) {
            return JSON.parse(await next(ws));
        }

        /** Read binary messages until this many bytes came. */
        async function receive(ws, bytes) {
            const chunks = [];
            let total = 0;

            while (total < bytes) {
                const chunk = await next(ws);
                chunks.push(chunk);
                total += chunk.length;
            }

            return Buffer.concat(chunks);
        }

        function listen(server, port = 0) {
            return new Promise((resolve) => server.listen(port, '127.0.0.1', () => resolve(server.address().port)));
        }

        /** A TCP server that sends back what it gets; "bye" makes it hang up. Its connections' ends are noted. */
        async function echoServer(port) {
            const ended = [];
            const server = net.createServer((socket) => {
                socket.on('data', (data) => (String(data) === 'bye' ? socket.end() : socket.write(data)));
                socket.on('close', () => ended.push(true));
                socket.on('error', () => {});
            });

            return { port: await listen(server, port), ended, server };
        }

        /** Connect to a local port; resolves to the socket, or the error's code. */
        function connect(port) {
            return new Promise((resolve) => {
                const socket = net.connect(port, '127.0.0.1', () => resolve(socket));
                socket.on('error', (error) => resolve(error.code));
            });
        }

        function read(socket, bytes) {
            return new Promise((resolve) => {
                let data = Buffer.alloc(0);
                const onData = (chunk) => {
                    data = Buffer.concat([data, chunk]);

                    if (data.length >= bytes) {
                        socket.off('data', onData);
                        resolve(String(data));
                    }
                };
                socket.on('data', onData);
            });
        }

        /** Play the app's side: dial each `open` at the local port target(message) gives, over a dial WebSocket. */
        async function serveDials(control, target) {
            while (true) {
                const message = await nextJson(control);

                if (message.type !== 'open') {
                    continue;
                }

                const tcp = net.connect(target(message), '127.0.0.1');
                const ws = await open(`dial=${message.id}&token=${message.token}`);
                tcp.on('data', (data) => ws.send(data));
                tcp.on('close', () => ws.close());
                ws.onmessage = (event) => tcp.write(Buffer.from(event.data));
                ws.onclose = () => tcp.end();
            }
        }

        function networkFile() {
            return JSON.parse(readFileSync(args.networkFile, 'utf8'));
        }

        JS;

    $result = Process::env([
        'ARGS' => json_encode($args),
        'TUNNEL_URL' => $url,
    ])->timeout(30)->run(['node', '--input-type=module', '-e', $prelude."\n".$script]);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output());

    return json_decode($result->output(), true);
}

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/onedrop-tunnel-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$this->home}/.onedrop");
    $this->key = 'tunnel-key-'.bin2hex(random_bytes(8));
    File::put("{$this->home}/.onedrop/tunnel-key", $this->key."\n");
    $this->port = tunnelFreePort();
    $this->proxyPort = tunnelFreePort();
    $this->networkFile = "{$this->home}/.onedrop/network.json";
    $this->url = "ws://127.0.0.1:{$this->port}/__onedrop/tunnel";
    $this->processes = [];

    $this->processes[] = Process::env([
        'HOME' => $this->home,
        'TUNNEL_PORT' => $this->port,
        'TUNNEL_PROXY_PORT' => $this->proxyPort,
    ])->start(['node', base_path('docker/sandbox/tunnel.mjs')]);

    tunnelWaitFor("http://127.0.0.1:{$this->port}/health");

    $this->ticket = fn (array $payload) => tunnelTicket($this->key, $payload + ['exp' => time() + 60, 'u' => 1]);
});

afterEach(function () {
    foreach ($this->processes as $process) {
        $process->stop(1);
    }

    File::deleteDirectory($this->home);
});

test('a forward carries a TCP connection both ways, and either side closing closes the other', function () {
    $port = tunnelFreePort();

    $result = tunnelScenario($this->url, ['port' => $port, 'ticket' => ($this->ticket)(['p' => 'forward', 'port' => $port])], <<<'JS'
        const echo = await echoServer(args.port);
        const ws = await open(`ticket=${args.ticket}`);

        ws.send(Buffer.from('hello'));
        const hello = String(await next(ws));

        // One message far larger than a frame's 16-bit length, back in many pieces.
        const big = Buffer.alloc(3 * 1024 * 1024, 'x');
        big.write('start');
        big.write('end', big.length - 3);
        ws.send(big);
        const back = await receive(ws, big.length);

        // The service hanging up closes the WebSocket.
        ws.send(Buffer.from('bye'));
        const closedByService = await ws.closed;

        // The app closing the WebSocket closes the service's connection.
        const second = await open(`ticket=${args.ticket}`);
        second.send(Buffer.from('ping'));
        await next(second);
        second.close();
        await second.closed;
        await sleep(200);

        done({ hello, bigMatches: back.equals(big), closedByService, servicesEnded: echo.ended.length });
        JS);

    expect($result)->toBe([
        'hello' => 'hello',
        'bigMatches' => true,
        'closedByService' => ['code' => 1000, 'reason' => ''],
        'servicesEnded' => 2,
    ]);
})->group('DESK-007');

test('a forward to a port nothing listens on closes with 1011 and the reason', function () {
    $port = tunnelFreePort();

    $result = tunnelScenario($this->url, ['ticket' => ($this->ticket)(['p' => 'forward', 'port' => $port])], <<<'JS'
        const ws = await open(`ticket=${args.ticket}`);

        done(await ws.closed);
        JS);

    expect($result['code'])->toBe(1011)
        ->and($result['reason'])->toContain('ECONNREFUSED');
})->group('DESK-007');

test('the tunnel refuses bad, expired and wrongly signed tickets, and forwards to its own ports', function () {
    $port = tunnelFreePort();
    $good = ($this->ticket)(['p' => 'forward', 'port' => $port]);
    [$body, $signature] = explode('.', $good);
    $tickets = [
        'good' => $good,
        'tampered' => tunnelTicket($this->key, ['p' => 'forward', 'port' => $port + 1, 'exp' => time() + 60]).'x',
        'forged body' => rtrim(strtr(base64_encode(json_encode(['p' => 'forward', 'port' => 22, 'exp' => time() + 60])), '+/', '-_'), '=').'.'.$signature,
        'expired' => tunnelTicket($this->key, ['p' => 'forward', 'port' => $port, 'exp' => time() - 1]),
        'other key' => tunnelTicket('another-key', ['p' => 'forward', 'port' => $port, 'exp' => time() + 60]),
        'unknown purpose' => ($this->ticket)(['p' => 'shell', 'port' => $port]),
        'tunnel port' => ($this->ticket)(['p' => 'forward', 'port' => $this->port]),
        'proxy port' => ($this->ticket)(['p' => 'forward', 'port' => $this->proxyPort]),
        'no ticket' => '',
        'not a ticket' => $body,
    ];

    $result = tunnelScenario($this->url, ['port' => $port, 'tickets' => $tickets], <<<'JS'
        await echoServer(args.port);
        const opened = {};

        for (const [name, ticket] of Object.entries(args.tickets)) {
            const ws = await open(`ticket=${encodeURIComponent(ticket)}`);
            opened[name] = ws !== null;
            ws?.close();
        }

        done(opened);
        JS);

    expect($result)->toBe([
        'good' => true,
        'tampered' => false,
        'forged body' => false,
        'expired' => false,
        'other key' => false,
        'unknown purpose' => false,
        'tunnel port' => false,
        'proxy port' => false,
        'no ticket' => false,
        'not a ticket' => false,
    ]);
})->group('DESK-007');

test('a new key works at once, without a restart', function () {
    $port = tunnelFreePort();
    $old = ($this->ticket)(['p' => 'forward', 'port' => $port]);
    File::put("{$this->home}/.onedrop/tunnel-key", 'the-new-key');
    $new = tunnelTicket('the-new-key', ['p' => 'forward', 'port' => $port, 'exp' => time() + 60]);

    $result = tunnelScenario($this->url, ['port' => $port, 'old' => $old, 'new' => $new], <<<'JS'
        await echoServer(args.port);
        const old = await open(`ticket=${args.old}`);
        const fresh = await open(`ticket=${args.new}`);

        done({ old: old !== null, new: fresh !== null });
        JS);

    expect($result)->toBe(['old' => false, 'new' => true]);
})->group('DESK-007');

test('the network listens for each host, and a connection to one is dialed by the app over a one-time dial', function () {
    $hostPort = tunnelFreePort();
    $takenPort = tunnelFreePort();

    $result = tunnelScenario($this->url, [
        'ticket' => ($this->ticket)(['p' => 'network']),
        'hostPort' => $hostPort,
        'takenPort' => $takenPort,
        'networkFile' => $this->networkFile,
    ], <<<'JS'
        // Something in the sandbox already has the second host's port.
        await listen(net.createServer(), args.takenPort);
        // The host on the user's network, as the app reaches it.
        const db = await echoServer();

        const control = await open(`ticket=${args.ticket}`);
        control.send(JSON.stringify({
            type: 'hosts',
            through: "Jeff's MacBook",
            hosts: [
                { host: 'DB.internal', port: args.hostPort },
                { host: 'other.internal', port: args.takenPort },
                { host: 'not a host!', port: 80 },
                { host: 'no-port.internal' },
            ],
        }));
        const listening = await nextJson(control);
        const file = networkFile();

        const client = await connect(Number(listening.hosts[0].address.split(':')[1]));
        const opening = await nextJson(control);
        client.write('over the network');

        // The app dials, with the one-time token.
        const tcp = net.connect(db.port, '127.0.0.1');
        const dial = await open(`dial=${opening.id}&token=${opening.token}`);
        tcp.on('data', (data) => dial.send(data));
        dial.onmessage = (event) => tcp.write(Buffer.from(event.data));
        const echoed = await read(client, 'over the network'.length);

        const again = await open(`dial=${opening.id}&token=${opening.token}`);
        const unknown = await open(`dial=nope&token=${opening.token}`);

        // The app refuses the next one: the tunnel hangs up on its client.
        const refusedClient = await connect(Number(listening.hosts[0].address.split(':')[1]));
        const refusedClosed = new Promise((resolve) => refusedClient.on('close', () => resolve(true)));
        const refusing = await nextJson(control);
        const wrongToken = await open(`dial=${refusing.id}&token=wrong`);
        control.send(JSON.stringify({ type: 'refused', id: refusing.id, reason: 'Not on this network' }));

        done({
            listening,
            file,
            opening: { type: opening.type, host: opening.host, port: opening.port, hasId: !!opening.id, hasToken: !!opening.token },
            echoed,
            reused: again !== null,
            unknown: unknown !== null,
            wrongToken: wrongToken !== null,
            refusedClosed: await refusedClosed,
        });
        JS);

    $proxy = "http://127.0.0.1:{$this->proxyPort}";
    $hosts = [
        ['host' => 'db.internal', 'port' => $hostPort, 'address' => "127.0.0.1:{$hostPort}"],
        ['host' => 'other.internal', 'port' => $takenPort, 'address' => '127.0.0.1:15000'],
    ];

    // The spare port is the first free one from 15000, which may be taken on the machine running the tests.
    $hosts[1]['address'] = $result['listening']['hosts'][1]['address'];

    expect((int) explode(':', $hosts[1]['address'])[1])->toBeGreaterThanOrEqual(15000)
        ->and($result['listening'])->toBe(['type' => 'listening', 'hosts' => $hosts, 'proxy' => $proxy])
        ->and($result['file'])->toBe(['connected' => true, 'through' => "Jeff's MacBook", 'proxy' => $proxy, 'hosts' => $hosts])
        ->and($result['opening'])->toBe(['type' => 'open', 'host' => 'db.internal', 'port' => $hostPort, 'hasId' => true, 'hasToken' => true])
        ->and($result['echoed'])->toBe('over the network')
        ->and($result['reused'])->toBeFalse()
        ->and($result['unknown'])->toBeFalse()
        ->and($result['wrongToken'])->toBeFalse()
        ->and($result['refusedClosed'])->toBeTrue();
})->group('DESK-009');

test('a new control connection replaces the old one, whose listeners close, and the network is off once it goes', function () {
    $result = tunnelScenario($this->url, [
        'ticket' => ($this->ticket)(['p' => 'network']),
        'hostPort' => tunnelFreePort(),
        'networkFile' => $this->networkFile,
    ], <<<'JS'
        const hosts = (through) => JSON.stringify({ type: 'hosts', through, hosts: [{ host: 'db.internal', port: args.hostPort }] });

        const first = await open(`ticket=${args.ticket}`);
        first.send(hosts('First'));
        const firstPort = Number((await nextJson(first)).hosts[0].address.split(':')[1]);

        const second = await open(`ticket=${args.ticket}`);
        const firstClosed = await first.closed;
        await sleep(100);
        const afterReplacing = { listener: typeof (await connect(firstPort)) === 'string', connected: networkFile().connected };

        second.send(hosts('Second'));
        const secondPort = Number((await nextJson(second)).hosts[0].address.split(':')[1]);
        const whileSecond = networkFile();

        second.close();
        await second.closed;
        await sleep(200);

        done({
            firstClosed,
            afterReplacing,
            whileSecond: { connected: whileSecond.connected, through: whileSecond.through },
            afterClosing: networkFile(),
            listenerRefused: (await connect(secondPort)) === 'ECONNREFUSED',
        });
        JS);

    expect($result['firstClosed']['code'])->toBe(1000)
        ->and($result['afterReplacing'])->toBe(['listener' => true, 'connected' => false])
        ->and($result['whileSecond'])->toBe(['connected' => true, 'through' => 'Second'])
        ->and($result['afterClosing'])->toBe([
            'connected' => false,
            'through' => 'Second',
            'proxy' => "http://127.0.0.1:{$this->proxyPort}",
            'hosts' => [],
        ])
        ->and($result['listenerRefused'])->toBeTrue();
})->group('DESK-009');

test('the HTTP proxy reaches listed hosts by name, with CONNECT and plain HTTP, and refuses the rest', function () {
    $result = tunnelScenario($this->url, [
        'ticket' => ($this->ticket)(['p' => 'network']),
        'webPort' => tunnelFreePort(),
        'proxyPort' => $this->proxyPort,
    ], <<<'JS'
        // The intranet site on the user's network: says which host and path it was asked for.
        const web = http.createServer((req, res) => res.end(`${req.headers.host} ${req.url}`));
        await listen(web, args.webPort);

        const control = await open(`ticket=${args.ticket}`);
        control.send(JSON.stringify({ type: 'hosts', hosts: [{ host: 'intranet.test', port: args.webPort }] }));
        await nextJson(control);
        serveDials(control, () => args.webPort);

        const viaProxy = (target, host) => new Promise((resolve) => {
            http.get({ host: '127.0.0.1', port: args.proxyPort, path: target, headers: { host } }, (res) => {
                let body = '';
                res.on('data', (chunk) => (body += chunk));
                res.on('end', () => resolve({ status: res.statusCode, body }));
            });
        });

        const tunnelled = (target) => new Promise((resolve) => {
            const req = http.request({ host: '127.0.0.1', port: args.proxyPort, method: 'CONNECT', path: target });
            req.on('connect', (res, socket) => {
                if (res.statusCode !== 200) {
                    resolve({ status: res.statusCode });
                    socket.destroy();

                    return;
                }

                socket.write(`GET /over-connect HTTP/1.1\r\nHost: ${target}\r\nConnection: close\r\n\r\n`);
                let response = '';
                socket.on('data', (chunk) => (response += chunk));
                socket.on('end', () => resolve({ status: 200, body: response.split('\r\n\r\n')[1] }));
            });
            req.end();
        });

        done({
            plain: await viaProxy(`http://intranet.test:${args.webPort}/page?x=1`, `intranet.test:${args.webPort}`),
            unlisted: (await viaProxy('http://elsewhere.test/', 'elsewhere.test')).status,
            wrongPort: (await viaProxy(`http://intranet.test/`, 'intranet.test')).status,
            notAbsolute: (await viaProxy('/page', 'intranet.test')).status,
            connect: await tunnelled(`intranet.test:${args.webPort}`),
            connectUnlisted: await tunnelled('elsewhere.test:443'),
        });
        JS);

    $port = $result['plain']['body'] ? explode(' ', $result['plain']['body'])[0] : null;

    expect($result['plain'])->toBe(['status' => 200, 'body' => "{$port} /page?x=1"])
        ->and($port)->toStartWith('intranet.test:')
        ->and($result['unlisted'])->toBe(403)
        ->and($result['wrongPort'])->toBe(403)
        ->and($result['notAbsolute'])->toBe(403)
        ->and($result['connect']['status'])->toBe(200)
        ->and($result['connect']['body'])->toBe("{$port} /over-connect")
        ->and($result['connectUnlisted'])->toBe(['status' => 403]);
})->group('DESK-009');

test('the host proxy passes /__onedrop/tunnel WebSockets to the tunnel, 404s plain requests there, and never routes to it', function () {
    $hostProxyPort = tunnelFreePort();
    $routes = "{$this->home}/routes.json";
    File::put($routes, json_encode(['/t' => $this->port]));

    $this->processes[] = Process::env([
        'PORT' => tunnelFreePort(),
        'PROXY_PORT' => $hostProxyPort,
        'TUNNEL_PORT' => $this->port,
        'ONEDROP_ROUTES_FILE' => $routes,
    ])->start(['node', base_path('docker/sandbox/host-proxy.mjs')]);

    tunnelWaitFor("http://127.0.0.1:{$hostProxyPort}/__onedrop/tunnel");

    $port = tunnelFreePort();
    $result = tunnelScenario($this->url, [
        'port' => $port,
        'ticket' => ($this->ticket)(['p' => 'forward', 'port' => $port]),
        'hostProxy' => "ws://127.0.0.1:{$hostProxyPort}/__onedrop/tunnel",
        'hostProxyHttp' => "http://127.0.0.1:{$hostProxyPort}",
    ], <<<'JS'
        await echoServer(args.port);
        const ws = await open(`ticket=${args.ticket}`, args.hostProxy);
        ws.send(Buffer.from('through the front door'));
        const echoed = String(await next(ws));
        const refused = await open('ticket=nope', args.hostProxy);

        done({
            echoed,
            refused: refused === null,
            plain: (await fetch(`${args.hostProxyHttp}/__onedrop/tunnel?ticket=${args.ticket}`)).status,
            routed: await (await fetch(`${args.hostProxyHttp}/t/health`)).text(),
        });
        JS);

    expect($result['echoed'])->toBe('through the front door')
        ->and($result['refused'])->toBeTrue()
        ->and($result['plain'])->toBe(404)
        ->and($result['routed'])->not->toContain('version');
})->group('DESK-007');

test('tunnel ensure saves the key privately and starts the tunnel once', function () {
    $port = tunnelFreePort();
    $env = [
        'HOME' => $this->home,
        'ONEDROP_TUNNEL_KEY' => 'ensured-key',
        'TUNNEL_PORT' => $port,
        'TUNNEL_PROXY_PORT' => tunnelFreePort(),
    ];
    $ensure = fn () => Process::env($env)->timeout(20)->run([base_path('docker/sandbox/tunnel'), 'ensure']);

    $first = $ensure();
    $health = json_decode((string) @file_get_contents("http://127.0.0.1:{$port}/health"), true);
    $second = $ensure();
    $again = json_decode((string) @file_get_contents("http://127.0.0.1:{$port}/health"), true);

    if (isset($health['pid'])) {
        posix_kill($health['pid'], SIGTERM);
    }

    expect($first->successful())->toBeTrue($first->errorOutput())
        ->and($second->successful())->toBeTrue($second->errorOutput())
        ->and(File::get("{$this->home}/.onedrop/tunnel-key"))->toBe('ensured-key')
        ->and(fileperms("{$this->home}/.onedrop/tunnel-key") & 0777)->toBe(0600)
        ->and($health['version'])->toBe(hash_file('sha256', base_path('docker/sandbox/tunnel.mjs')))
        ->and($again['pid'])->toBe($health['pid'])
        ->and(Process::env(['HOME' => $this->home])->run([base_path('docker/sandbox/tunnel'), 'ensure'])->failed())->toBeTrue();
})->group('DESK-007');
