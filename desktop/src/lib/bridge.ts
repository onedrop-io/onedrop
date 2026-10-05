import { router } from '@inertiajs/react';
import { invoke } from '@tauri-apps/api/core';
import { listen } from '@tauri-apps/api/event';
import type {
    DesktopDeviceStatus,
    DesktopDockerStatus,
    DesktopForward,
    DesktopNetworkStatus,
    OnedropDesktop,
} from '@/types/desktop';

/** A page the app asked for: from the menu bar, the new-project shortcut or an `onedrop://` link (DESK-011). */
type Navigation = { path: string; focus: string | null };

/** What the app says when its services start: who this computer is, and a page asked for before the pages ran. */
type Started = {
    version: string;
    device: { id: number; name: string };
    pending: Navigation | null;
};

/** Focus an element once the page shows it (the new-project prompt), trying for a moment while the page renders. */
function focusWhenThere(selector: string, tries = 20): void {
    const element = document.querySelector<HTMLElement>(selector);

    if (element) {
        element.focus();
    } else if (tries > 0) {
        window.setTimeout(() => focusWhenThere(selector, tries - 1), 100);
    }
}

function show({ path, focus }: Navigation): void {
    router.visit(path, {
        onFinish: () => {
            if (focus) {
                focusWhenThere(focus);
            }
        },
    });
}

/**
 * `window.onedropDesktop` (DESK-006..011): what the web pages can ask of the app, backed by its Tauri commands.
 * Starts the app's services for the signed-in session (forwards, the network, the device link, the menu bar's
 * projects); set before the pages start, since they check for it.
 */
export async function provideDesktop(): Promise<() => void> {
    const started = await invoke<Started>('desktop_start');
    const listeners = new Set<() => void>();

    await listen('desktop://changed', () =>
        listeners.forEach((listener) => listener()),
    );
    await listen<Navigation>('desktop://navigate', (event) =>
        show(event.payload),
    );

    const desktop: OnedropDesktop = {
        version: started.version,
        device: started.device,

        forwards: {
            list: (projectId) =>
                invoke<DesktopForward[]>('forwards_list', {
                    projectId: projectId ?? null,
                }),
            start: (projectId, remotePort, localPort) =>
                invoke<DesktopForward>('forwards_start', {
                    projectId,
                    remotePort,
                    localPort: localPort ?? null,
                }),
            stop: (projectId, remotePort) =>
                invoke('forwards_stop', { projectId, remotePort }),
        },

        editor: {
            configured: () => invoke<boolean>('editor_configured'),
            open: (projectId, alias, editor) =>
                invoke('editor_open', { projectId, alias, editor }),
        },

        network: {
            status: (projectId) =>
                invoke<DesktopNetworkStatus>('network_status', { projectId }),
            setSharing: (projectId, sharing) =>
                invoke<DesktopNetworkStatus>('network_set_sharing', {
                    projectId,
                    sharing,
                }),
        },

        docker: {
            status: () => invoke<DesktopDockerStatus>('docker_status'),
            prepare: () => invoke<DesktopDockerStatus>('docker_prepare'),
        },

        devices: {
            status: () => invoke<DesktopDeviceStatus>('devices_status'),
        },

        onChange(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
    };

    window.onedropDesktop = desktop;

    // The page asked for before the pages started (an `onedrop://` link that opened the app), once they have.
    return () => {
        if (started.pending) {
            show(started.pending);
        }
    };
}
