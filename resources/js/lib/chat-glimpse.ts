import type { ChatMessage } from '@/types';

/**
 * The last few messages of each chat this browser showed, so opening it again shows them while the page loads
 * (PRJ-002). Kept in `localStorage` for the signed-in user, cleared when they log out.
 */
export type GlimpseMessage = { role: 'user' | 'assistant'; text: string };

const KEY = 'onedrop.glimpses';
/** Messages kept per chat. */
const MESSAGES = 6;
/** Chats kept, the least recently shown dropped first. */
const CHATS = 20;
/** Characters kept per message: enough for a few lines. */
const LENGTH = 400;

type Stored = {
    user: number;
    chats: Record<string, { at: number; messages: GlimpseMessage[] }>;
};

function read(user: number): Stored {
    try {
        const stored = JSON.parse(
            window.localStorage.getItem(KEY) ?? 'null',
        ) as Stored | null;

        return stored?.user === user && typeof stored.chats === 'object'
            ? stored
            : { user, chats: {} };
    } catch {
        return { user, chats: {} };
    }
}

/** Markdown down to its words, on one line. */
export function plain(content: string): string {
    return content
        .replace(/```[\s\S]*?(```|$)/g, ' ')
        .split('\n')
        .map((line) =>
            line.replace(/^\s*(#{1,6}|>|[-*+]|\d+\.)\s+/, '').trim(),
        )
        .join(' ')
        .replace(/`([^`]*)`/g, '$1')
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/(\*\*|__|~~)(.+?)\1/g, '$2')
        .replace(/(^|\W)[*_](\S(?:.*?\S)?)[*_](?=\W|$)/g, '$1$2')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, LENGTH);
}

export function saveGlimpse(
    user: number,
    path: string,
    messages: ChatMessage[],
): void {
    const kept = messages
        .filter(
            (message): message is ChatMessage & GlimpseMessage =>
                message.role === 'user' || message.role === 'assistant',
        )
        .map((message) => ({
            role: message.role,
            text: plain(message.content),
        }))
        .filter((message) => message.text !== '')
        .slice(-MESSAGES);

    const stored = read(user);
    stored.chats[path] = { at: Date.now(), messages: kept };

    const newest = Object.entries(stored.chats)
        .sort(([, a], [, b]) => b.at - a.at)
        .slice(0, CHATS);

    try {
        window.localStorage.setItem(
            KEY,
            JSON.stringify({ user, chats: Object.fromEntries(newest) }),
        );
    } catch {
        // Storage full or blocked: the loading sketch just shows no messages.
    }
}

export function loadGlimpse(user: number, path: string): GlimpseMessage[] {
    return read(user).chats[path]?.messages ?? [];
}

export function clearGlimpses(): void {
    try {
        window.localStorage.removeItem(KEY);
    } catch {
        // Nothing to clear.
    }
}

const sendKey = (path: string) => `onedrop.send-on-load.${path}`;

/** The message typed while the chat loaded was sent: send it once the chat is there. */
export function sendOnLoad(path: string): void {
    try {
        window.sessionStorage.setItem(sendKey(path), '1');
    } catch {
        // The draft is still kept; it just isn't sent by itself.
    }
}

/** Whether a message is waiting to be sent from the loading sketch; asks once. */
export function takeSendOnLoad(path: string): boolean {
    try {
        const waiting = window.sessionStorage.getItem(sendKey(path)) === '1';
        window.sessionStorage.removeItem(sendKey(path));

        return waiting;
    } catch {
        return false;
    }
}
