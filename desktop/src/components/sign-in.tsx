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
 * Sign in (DESK-001): the user names their OneDrop (a team's server, or the one on this computer), and finishes
 * signing in in their browser, where their passkeys, single sign-on and saved passwords already are.
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
            'http://localhost:8000',
    );
    const [waiting, setWaiting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        setWaiting(true);
        localStorage.setItem(LAST_SERVER_KEY, server);

        try {
            await signIn(server);
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
                            You'll finish signing in in your browser.
                        </p>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="server">Your OneDrop's address</Label>
                        <Input
                            id="server"
                            value={server}
                            onChange={(event) => setServer(event.target.value)}
                            placeholder="onedrop.example.com"
                            autoFocus
                            autoCapitalize="off"
                            autoCorrect="off"
                            spellCheck={false}
                            disabled={waiting}
                            data-test="server"
                        />
                        <p className="text-xs text-muted-foreground">
                            Installed on this computer? It's
                            http://localhost:8000.
                        </p>
                        <InputError message={error ?? undefined} />
                    </div>

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
                </form>
            </div>
        </div>
    );
}
