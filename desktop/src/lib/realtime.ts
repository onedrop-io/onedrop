import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useEffect, useRef, useState } from 'react';
import type { RealtimeConfig } from '@/lib/realtime';
import { api, session } from './api';

let echo: Echo<'reverb'> | null = null;
let connectedTo: string | null = null;

/**
 * Connect to the server's Reverb (LIVE-001) once per sign-in, signing private channels in with the API token.
 * Null when the server doesn't broadcast: callers poll instead.
 */
function connect(config: RealtimeConfig | null): Echo<'reverb'> | null {
    if (!config) {
        return null;
    }

    const { server, token } = session();
    const key = JSON.stringify([config, server, token]);

    if (echo && connectedTo === key) {
        return echo;
    }

    echo?.disconnect();
    connectedTo = key;
    echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: config.key,
        // Servers' browsers come in on the app's own address.
        wsHost: config.host ?? new URL(server).hostname,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            customHandler: (
                {
                    socketId,
                    channelName,
                }: { socketId: string; channelName: string },
                callback: (error: Error | null, data: unknown) => void,
            ) => {
                api('broadcasting/auth', {
                    socket_id: socketId,
                    channel_name: channelName,
                })
                    .then((data) => callback(null, data))
                    .catch((error: Error) => callback(error, null));
            },
        },
    } as ConstructorParameters<typeof Echo<'reverb'>>[0]);

    return echo;
}

/** Leave Reverb, e.g. on signing out. */
export function disconnectRealtime(): void {
    echo?.disconnect();
    echo = null;
    connectedTo = null;
}

/**
 * Listen to a project's private channel, as the web workspace does. Returns whether live updates are flowing;
 * while false, poll instead.
 *
 * @param handlers Event name (e.g. `ProjectUpdated`) → what to do.
 */
export function useProjectChannel(
    config: RealtimeConfig | null,
    projectId: number,
    handlers: Record<string, () => void>,
): boolean {
    const [live, setLive] = useState(false);
    const latest = useRef(handlers);
    const configKey = JSON.stringify(config);
    const events = Object.keys(handlers).sort().join(',');

    useEffect(() => {
        latest.current = handlers;
    });

    useEffect(() => {
        const client = connect(JSON.parse(configKey) as RealtimeConfig | null);

        if (!client) {
            return;
        }

        const name = `project.${projectId}`;
        const channel = client.private(name);
        let subscribed = false;
        let connected = client.connectionStatus() === 'connected';
        const update = () => setLive(subscribed && connected);

        channel.subscribed(() => {
            subscribed = true;
            update();
        });
        channel.error(() => {
            subscribed = false;
            update();
        });

        for (const event of events.split(',')) {
            channel.listen(`.${event}`, () => latest.current[event]?.());
        }

        const stopWatching = client.connector.onConnectionChange((status) => {
            connected = status === 'connected';
            update();
        });

        return () => {
            stopWatching();
            client.leave(name);
            setLive(false);
        };
    }, [configKey, projectId, events]);

    return live;
}
