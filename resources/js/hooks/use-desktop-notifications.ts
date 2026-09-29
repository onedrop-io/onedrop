import { useSyncExternalStore } from 'react';

const ENABLED_KEY = 'desktop_notifications';
const DISMISSED_KEY = 'desktop_notifications_prompt_dismissed';

/** "unsupported" when the browser has no Notification API (or the page isn't served securely). */
export type DesktopNotificationStatus = 'unsupported' | 'denied' | 'off' | 'on';

const listeners = new Set<() => void>();

const notify = (): void => listeners.forEach((listener) => listener());

const subscribe = (listener: () => void): (() => void) => {
    listeners.add(listener);
    // The user can change the browser permission from the address bar at any time.
    window.addEventListener('focus', listener);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('focus', listener);
    };
};

export function desktopNotificationStatus(): DesktopNotificationStatus {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'denied';
    }

    return Notification.permission === 'granted' &&
        localStorage.getItem(ENABLED_KEY) === 'true'
        ? 'on'
        : 'off';
}

/** Asks the browser for permission (the first time) and turns notifications on if it's given. */
async function enable(): Promise<void> {
    const permission = await Notification.requestPermission();

    if (permission === 'granted') {
        localStorage.setItem(ENABLED_KEY, 'true');
        // Confirms right away that the browser and the OS let notifications through.
        new Notification('Notifications are on', {
            body: "You'll be notified here when a project is ready for your review.",
            icon: '/favicon.svg',
        });
    }

    notify();
}

function disable(): void {
    localStorage.removeItem(ENABLED_KEY);
    notify();
}

/** "Not now" on the in-chat prompt: don't offer again in this browser (Settings still can turn them on). */
function dismissPrompt(): void {
    localStorage.setItem(DISMISSED_KEY, 'true');
    notify();
}

const promptDismissed = (): boolean =>
    typeof window !== 'undefined' &&
    localStorage.getItem(DISMISSED_KEY) === 'true';

export function useDesktopNotifications() {
    const status = useSyncExternalStore(
        subscribe,
        desktopNotificationStatus,
        () => 'unsupported' as const,
    );
    const dismissed = useSyncExternalStore(
        subscribe,
        promptDismissed,
        () => true,
    );

    return { status, dismissed, enable, disable, dismissPrompt } as const;
}
