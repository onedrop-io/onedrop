import { relaunch } from '@tauri-apps/plugin-process';
import { check } from '@tauri-apps/plugin-updater';
import { toast } from 'sonner';
import { serverReleasedAt } from './transport';

/** How often the app looks for a new version while it's open. */
const EVERY_MS = 4 * 60 * 60 * 1000;

let ready = false;

/**
 * A new version (DESK-004): downloaded in the background, then offered with "Restart". Releases come from the
 * desktop workflow; the updater only installs ones signed with the app's key.
 */
async function lookForUpdate(): Promise<void> {
    if (ready) {
        return;
    }

    const update = await check();

    if (!update) {
        return;
    }

    await update.downloadAndInstall();
    ready = true;

    toast(`OneDrop ${update.version} is ready`, {
        description: 'Restart the app to use it.',
        duration: Infinity,
        action: { label: 'Restart', onClick: () => void relaunch() },
    });
}

/** Look for updates now and every few hours, in built apps (development runs from source). */
export function keepUpdated(): void {
    if (import.meta.env.DEV) {
        return;
    }

    const look = () => void lookForUpdate().catch(() => {});

    look();
    window.setInterval(look, EVERY_MS);
}

/** When this app's release was made (a Unix time), stamped by the desktop workflow; absent in development. */
const appReleasedAt = Number(import.meta.env.VITE_DESKTOP_RELEASED_AT) || null;

/**
 * The server runs an older OneDrop than the app: its pages may expect what the server doesn't send yet. Say so,
 * since only the server's owner can update it.
 */
export function warnIfServerIsOlder(): void {
    if (
        appReleasedAt === null ||
        serverReleasedAt === null ||
        serverReleasedAt >= appReleasedAt
    ) {
        return;
    }

    toast.warning('This OneDrop is older than the app', {
        description:
            'Some pages may not work until it’s updated. On the server, run `drop update`.',
        duration: Infinity,
    });
}
