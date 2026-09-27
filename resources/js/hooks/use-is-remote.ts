import { useEffect, useState } from 'react';

const LOCAL = ['localhost', '127.0.0.1', '::1', '[::1]'];

export function isLocalHostname(hostname: string): boolean {
    return (
        LOCAL.includes(hostname) ||
        hostname.endsWith('.localhost') ||
        hostname.endsWith('.test')
    );
}

/**
 * Whether the app is being used from another machine (e.g. through a Tailscale
 * Funnel), where this machine's 127.0.0.1 preview and shell URLs can't be reached.
 * Decided after mount so server-rendered and client HTML match.
 */
export function useIsRemote(): boolean {
    const [remote, setRemote] = useState(false);

    useEffect(() => {
        setRemote(!isLocalHostname(window.location.hostname));
    }, []);

    return remote;
}
