import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { api, ApiError, configureApi } from './lib/api';
import {
    clearSession,
    focusWindow,
    loadSession,
    saveSession,
    signInWithBrowser,
} from './lib/native';
import { disconnectRealtime } from './lib/realtime';
import type { Me, Session } from './lib/types';
import NeedsAi from './components/needs-ai';
import Shell from './components/shell';
import SignIn from './components/sign-in';
import TitleBar from './components/title-bar';

type State =
    | { kind: 'loading' }
    | { kind: 'signed-out'; server: string | null }
    | { kind: 'unreachable'; session: Session; message: string }
    | { kind: 'signed-in'; session: Session; me: Me };

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
async function exchange(
    server: string,
    grant: Awaited<ReturnType<typeof signInWithBrowser>>,
): Promise<string> {
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
        throw new ApiError(
            json.message ?? `Signing in failed (${response.status})`,
            response.status,
        );
    }

    return json.token;
}

export default function App() {
    const [state, setState] = useState<State>({ kind: 'loading' });

    const signOut = useCallback(async (revoke = true) => {
        if (revoke) {
            await api('desktop/token', undefined, 'DELETE').catch(() => {});
        }

        await clearSession().catch(() => {});
        disconnectRealtime();
        setState((current) => ({
            kind: 'signed-out',
            server: 'session' in current ? current.session.server : null,
        }));
    }, []);

    const open = useCallback(
        async (session: Session) => {
            configureApi(session, () => void signOut(false));

            try {
                const me = await api<Me>('user');

                setState({ kind: 'signed-in', session, me });
            } catch (error) {
                if (error instanceof ApiError && error.status === 401) {
                    return;
                }

                setState({
                    kind: 'unreachable',
                    session,
                    message: (error as Error).message,
                });
            }
        },
        [signOut],
    );

    useEffect(() => {
        loadSession()
            .then((session) =>
                session
                    ? open(session)
                    : setState({ kind: 'signed-out', server: null }),
            )
            .catch(() => setState({ kind: 'signed-out', server: null }));
    }, [open]);

    const signIn = async (input: string): Promise<void> => {
        const server = normalizeServer(input);
        const grant = await signInWithBrowser(server);
        const token = await exchange(server, grant);
        const session = { server, token };

        await saveSession(session);
        void focusWindow().catch(() => {});
        await open(session);
    };

    switch (state.kind) {
        case 'loading':
            return (
                <div className="flex h-screen flex-col">
                    <TitleBar />
                    <div className="flex flex-1 items-center justify-center">
                        <Spinner className="size-5 text-muted-foreground" />
                    </div>
                </div>
            );
        case 'signed-out':
            return <SignIn lastServer={state.server} onSignIn={signIn} />;
        case 'unreachable':
            return (
                <div className="flex h-screen flex-col">
                    <TitleBar />
                    <div className="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
                        <p
                            className="max-w-sm text-sm text-muted-foreground"
                            data-test="unreachable"
                        >
                            {state.message}
                        </p>
                        <div className="flex gap-2">
                            <Button onClick={() => void open(state.session)}>
                                Try again
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => void signOut(false)}
                            >
                                Sign out
                            </Button>
                        </div>
                    </div>
                </div>
            );
        case 'signed-in':
            return state.me.user.ai_connected ? (
                <Shell
                    me={state.me}
                    onSignOut={() => void signOut()}
                    onSwitched={() => void open(state.session)}
                />
            ) : (
                <NeedsAi
                    me={state.me}
                    onDone={() => void open(state.session)}
                    onSignOut={() => void signOut()}
                />
            );
    }
}
