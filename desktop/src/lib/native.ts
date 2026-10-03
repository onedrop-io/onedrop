import { invoke } from '@tauri-apps/api/core';
import { getCurrentWindow } from '@tauri-apps/api/window';
import { WebviewWindow } from '@tauri-apps/api/webviewWindow';
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

/**
 * Open a web address in a window of its own. Previews on a server sign in with a cookie on their own address,
 * which a frame inside the app can't keep, so a window of their own always works.
 */
export function openInWindow(url: string, title: string): void {
    const label = `preview-${Date.now()}`;

    new WebviewWindow(label, { url, title, width: 1200, height: 800 });
}

export function isMac(): boolean {
    return navigator.userAgent.includes('Mac');
}

/** Whether the user is looking at the app right now. */
export function isLooking(): boolean {
    return document.visibilityState === 'visible' && document.hasFocus();
}

/** A system notification, when the user has allowed them. Asks the first time. */
export async function notify(title: string, body: string): Promise<void> {
    let granted = await isPermissionGranted();

    if (!granted) {
        granted = (await requestPermission()) === 'granted';
    }

    if (granted) {
        sendNotification({ title, body });
    }
}

/** Bring the app's window to the front, e.g. after signing in in the browser. */
export async function focusWindow(): Promise<void> {
    await getCurrentWindow().setFocus();
}
