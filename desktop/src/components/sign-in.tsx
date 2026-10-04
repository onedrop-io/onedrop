import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    cancelSignIn,
    focusWindow,
    saveSession,
    signInWithBrowser,
} from '../lib/native';
import type { SignInGrant } from '../lib/native';
import Logo from './logo';

const LAST_SERVER_KEY = 'onedrop.server';

/** Where new users sign in: OneDrop's own hosted server. */
export const HOSTED_SERVER = 'https://onedrop.io';

/** OneDrop installed on this computer (`drop` serves it here). */
const LOCAL_SERVER = 'http://localhost:8000';

const SERVER_CHOICES = [
    { id: 'hosted', label: 'Use onedrop.io', value: HOSTED_SERVER },
    { id: 'local', label: 'Use this computer', value: LOCAL_SERVER },
];

const LINK = 'underline-offset-4 hover:text-foreground hover:underline';

/** `https://onedrop.io` → `onedrop.io`; `http://localhost:8000` stays as is, so it's clear it's not HTTPS. */
function displayServer(server: string): string {
    return server.replace(/^https:\/\//, '');
}

/** `localhost:8000` → `http://localhost:8000`; `onedrop.example.com/x` → `https://onedrop.example.com`. */
export function normalizeServer(input: string): string {
    const trimmed = input.trim();
    const local = /^(localhost|127\.0\.0\.1|\[::1\])(:|\/|$)/.test(trimmed);
    const url = new URL(
        /^https?:\/\//i.test(trimmed)
            ? trimmed
            : `${local ? 'http' : 'https'}://${trimmed}`,
    );

    return url.origin;
}

/** Trade the browser's one-time code for an API token (DESK-001). */
async function exchange(server: string, grant: SignInGrant): Promise<string> {
    const response = await fetch(new URL('/api/v1/desktop/token', server), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(grant),
    });
    const json = await response.json().catch(() => ({}));

    if (!response.ok || typeof json.token !== 'string') {
        throw new Error(
            json.message ?? `Signing in failed (${response.status})`,
        );
    }

    return json.token;
}

/** Sign in through the browser and keep the token in the keychain. */
async function signIn(input: string): Promise<void> {
    const server = normalizeServer(input);
    const grant = await signInWithBrowser(server);
    const token = await exchange(server, grant);

    await saveSession({ server, token });
    void focusWindow().catch(() => {});
}

/**
 * Sign in (DESK-001): to OneDrop's hosted server unless the user changes it (to a team's server, or the one on this
 * computer), finishing in their browser, where their passkeys, single sign-on and saved passwords already are.
 */
export default function SignIn({
    lastServer,
    onSignedIn,
}: {
    lastServer: string | null;
    onSignedIn: () => void;
}) {
    const [server, setServer] = useState(
        () =>
            lastServer ??
            localStorage.getItem(LAST_SERVER_KEY) ??
            HOSTED_SERVER,
    );
    const [changing, setChanging] = useState(false);
    const [waiting, setWaiting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        setWaiting(true);
        let origin: string;

        try {
            origin = normalizeServer(server);
        } catch {
            setError('That doesn’t look like an address.');
            setWaiting(false);

            return;
        }

        localStorage.setItem(LAST_SERVER_KEY, origin);
        setServer(origin);
        setChanging(false);

        try {
            await signIn(origin);
            onSignedIn();
        } catch (failure) {
            const message =
                failure instanceof Error ? failure.message : String(failure);

            if (message !== 'cancelled') {
                setError(message);
            }
        } finally {
            setWaiting(false);
        }
    };

    return (
        <div className="flex h-screen flex-col">
            <div className="flex flex-1 items-center justify-center p-8">
                <form
                    onSubmit={(event) => void submit(event)}
                    className="w-full max-w-sm space-y-6"
                >
                    <div className="flex flex-col items-center gap-3 text-center">
                        <Logo className="size-10" />
                        <h1 className="font-display text-2xl font-semibold">
                            Sign in to OneDrop
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            You’ll sign in or create an account in your browser.
                        </p>
                    </div>

                    {changing && (
                        <div className="grid gap-2">
                            <Label htmlFor="server">Server address</Label>
                            <Input
                                id="server"
                                value={server}
                                onChange={(event) =>
                                    setServer(event.target.value)
                                }
                                placeholder="onedrop.example.com"
                                autoFocus
                                autoCapitalize="off"
                                autoCorrect="off"
                                spellCheck={false}
                                disabled={waiting}
                                data-test="server"
                            />
                        </div>
                    )}

                    <InputError
                        message={error ?? undefined}
                        className="text-center"
                    />

                    {waiting ? (
                        <div className="space-y-2">
                            <Button type="button" className="w-full" disabled>
                                <Spinner />
                                Waiting for your browser…
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                className="w-full"
                                onClick={() => void cancelSignIn()}
                            >
                                Cancel
                            </Button>
                        </div>
                    ) : (
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={server.trim() === ''}
                            data-test="sign-in"
                        >
                            Sign in with your browser
                        </Button>
                    )}

                    {!waiting && (
                        <p
                            className="text-center text-xs text-muted-foreground"
                            data-test="current-server"
                        >
                            {changing ? (
                                SERVER_CHOICES.map((choice, index) => (
                                    <span key={choice.id}>
                                        {index > 0 && ' · '}
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setServer(choice.value);
                                                setChanging(
                                                    choice.value !==
                                                        HOSTED_SERVER,
                                                );
                                            }}
                                            className={LINK}
                                            data-test={`server-${choice.id}`}
                                        >
                                            {choice.label}
                                        </button>
                                    </span>
                                ))
                            ) : server === HOSTED_SERVER ? (
                                <button
                                    type="button"
                                    onClick={() => setChanging(true)}
                                    className={LINK}
                                    data-test="change-server"
                                >
                                    Use another server
                                </button>
                            ) : (
                                <>
                                    On {displayServer(server)} ·{' '}
                                    <button
                                        type="button"
                                        onClick={() => setChanging(true)}
                                        className={LINK}
                                        data-test="change-server"
                                    >
                                        Change
                                    </button>
                                </>
                            )}
                        </p>
                    )}
                </form>
            </div>
        </div>
    );
}
