import { router, usePage } from '@inertiajs/react';
import { configureEcho, echo, echoIsConfigured } from '@laravel/echo-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { jsonRequest } from '@/lib/json-request';

/** Where the browser connects for live updates (LIVE-001); null when the app doesn't broadcast. */
export type RealtimeConfig = {
    key: string;
    host: string | null;
    port: number;
    scheme: 'http' | 'https';
};

/**
 * Connect Echo to Reverb the first time a page needs it, from the address the server shares at runtime
 * (so one build works at any address). False when the app doesn't broadcast.
 */
function connect(config: RealtimeConfig | null): boolean {
    if (!config) {
        return false;
    }

    if (!echoIsConfigured()) {
        configureEcho({
            broadcaster: 'reverb',
            key: config.key,
            wsHost: config.host ?? window.location.hostname,
            wsPort: config.port,
            wssPort: config.port,
            forceTLS: config.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            // The session's CSRF token lives in the XSRF-TOKEN cookie (there's no meta tag); read it on each subscribe.
            channelAuthorization: {
                customHandler: (
                    { socketId, channelName },
                    callback: (error: Error | null, data: unknown) => void,
                ) => {
                    jsonRequest('/broadcasting/auth', {
                        socket_id: socketId,
                        channel_name: channelName,
                    })
                        .then((data) => callback(null, data))
                        .catch((error: Error) => callback(error, null));
                },
            },
        } as Parameters<typeof configureEcho>[0]);
    }

    return true;
}

/**
 * Listen to a project's private channel. Returns whether live updates are flowing (connected to Reverb and
 * subscribed); while false, callers should poll instead.
 *
 * @param handlers Event name (the event's class name, e.g. `ProjectUpdated`) → what to do with its payload.
 */
export function useProjectChannel(
    projectId: number,
    handlers: Record<string, (payload: never) => void>,
): boolean {
    return usePrivateChannel(`project.${projectId}`, handlers);
}

/**
 * Listen to a private channel (a project's, or Drive's, DRIVE-001). Returns whether live updates are flowing; while
 * false, callers should poll instead. A null name listens to nothing.
 */
export function usePrivateChannel(
    name: string | null,
    handlers: Record<string, (payload: never) => void>,
): boolean {
    return usePrivateChannels(name === null ? [] : [name], handlers);
}

/**
 * Listen to several private channels with the same handlers (the sidebar's busy projects). Returns whether live
 * updates are flowing on every one of them; while false, callers should poll instead. No names listens to nothing.
 */
export function usePrivateChannels(
    names: string[],
    handlers: Record<string, (payload: never) => void>,
): boolean {
    // By value: every reload brings a new props object, which mustn't resubscribe.
    const config = JSON.stringify(usePage().props.realtime);
    const [live, setLive] = useState(false);
    const latest = useRef(handlers);

    useEffect(() => {
        latest.current = handlers;
    });

    const events = Object.keys(handlers).sort().join(',');
    const channels = [...new Set(names)].sort().join(',');

    useEffect(() => {
        if (
            channels === '' ||
            !connect(JSON.parse(config) as RealtimeConfig | null)
        ) {
            return;
        }

        const list = channels.split(',');
        const subscribed = new Set<string>();
        let connected = echo().connectionStatus() === 'connected';
        const update = () =>
            setLive(connected && subscribed.size === list.length);

        for (const name of list) {
            const channel = echo().private(name);

            channel.subscribed(() => {
                subscribed.add(name);
                update();
            });
            channel.error(() => {
                subscribed.delete(name);
                update();
            });

            for (const event of events.split(',')) {
                channel.listen(`.${event}`, (payload: never) =>
                    latest.current[event]?.(payload),
                );
            }
        }

        const stopWatching = echo().connector.onConnectionChange((status) => {
            connected = status === 'connected';
            update();
        });

        return () => {
            stopWatching();
            list.forEach((name) => echo().leave(name));
            setLive(false);
        };
    }, [config, channels, events]);

    return live;
}

/**
 * Reload the given props when a live update says they changed. Updates arriving during a reload bring one more
 * reload after it, never a pile of them.
 */
export function useLiveReload(only: string[]): () => void {
    const state = useRef<'idle' | 'reloading' | 'again'>('idle');
    const props = only.join(',');

    return useCallback(() => {
        const reload = () => {
            state.current = 'reloading';
            router.reload({
                only: props.split(','),
                onFinish: () => {
                    const again = state.current === 'again';
                    state.current = 'idle';

                    if (again) {
                        reload();
                    }
                },
            });
        };

        if (state.current === 'idle') {
            reload();
        } else {
            state.current = 'again';
        }
    }, [props]);
}
