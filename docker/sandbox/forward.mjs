#!/usr/bin/env node
// Forwards the sandbox's preview port to the port a project's Docker Compose stack publishes (SBX-008), so the
// preview, the host proxy and published URLs reach the stack without the compose file changing.
// Usage: node forward.mjs <listen port> <target port>
import net from 'node:net';

const [listenPort, targetPort] = process.argv.slice(2).map(Number);

if (!listenPort || !targetPort) {
    console.error('Usage: forward.mjs <listen port> <target port>');
    process.exit(1);
}

net.createServer((client) => {
    const upstream = net.connect(targetPort, '127.0.0.1');
    const close = () => {
        client.destroy();
        upstream.destroy();
    };

    client.on('error', close);
    upstream.on('error', close);
    client.pipe(upstream).pipe(client);
}).listen(listenPort, '0.0.0.0');
