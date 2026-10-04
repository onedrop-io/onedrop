import { invoke } from '@tauri-apps/api/core';
import { getCurrentWindow } from '@tauri-apps/api/window';
import {
    isPermissionGranted,
    requestPermission,
    sendNotification,
} from '@tauri-apps/plugin-notification';
import { openUrl } from '@tauri-apps/plugin-opener';
import type { Session } from './types';

/** What the browser hands back after the user allows the app (DESK-001), ready to trade for a token. */
export type SignInGrant = {
    code: string;
    code_verifier: string;
    redirect_uri: string;
};

/** The saved session from the keychain, or null. */
export function loadSession(): Promise<Session | null> {
    return invoke<Session | null>('session_load');
}

export function saveSession(session: Session): Promise<void> {
    return invoke('session_save', { session });
}

export function clearSession(): Promise<void> {
    return invoke('session_clear');
}

/** Open the server's sign-in page in the browser and wait for the user to allow the app there. */
export function signInWithBrowser(server: string): Promise<SignInGrant> {
    return invoke<SignInGrant>('sign_in', { server });
}

export function cancelSignIn(): Promise<void> {
    return invoke('cancel_sign_in');
}

/** Open a web address in the user's browser. */
export function openInBrowser(url: string): Promise<void> {
    return openUrl(url);
}

/** Save a downloaded file in the Downloads folder and show it there. */
export function saveDownload(name: string, bytes: Uint8Array): Promise<void> {
    return invoke('save_download', { name, bytes: Array.from(bytes) });
}

/** Tell the window which server the app uses, so it opens that server's pages in the browser rather than leave. */
export function setServerOrigin(origin: string): Promise<void> {
    return invoke('use_server', { origin });
}

/** Bring the app's window to the front, e.g. after signing in in the browser. */
export async function focusWindow(): Promise<void> {
    await getCurrentWindow().setFocus();
}

/**
 * The browser's Notification API, which the web app uses for "ready for review" (NOTIF-001), as system
 * notifications: the app's webview has no notifications of its own.
 */
export async function provideNotifications(): Promise<void> {
    let granted = await isPermissionGranted().catch(() => false);

    class SystemNotification {
        static permission: NotificationPermission = granted
            ? 'granted'
            : 'default';

        static async requestPermission(): Promise<NotificationPermission> {
            granted = (await requestPermission()) === 'granted';
            SystemNotification.permission = granted ? 'granted' : 'denied';

            return SystemNotification.permission;
        }

        onclick: ((event: Event) => void) | null = null;

        constructor(title: string, options: NotificationOptions = {}) {
            if (granted) {
                sendNotification({ title, body: options.body });
            }
        }

        close(): void {}
    }

    Object.defineProperty(window, 'Notification', {
        value: SystemNotification,
        configurable: true,
    });
}
