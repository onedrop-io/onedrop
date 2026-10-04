import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { startApp } from '@/inertia-app';
import SignIn from './components/sign-in';
import Unreachable from './components/unreachable';
import { clearSession, loadSession, provideNotifications } from './lib/native';
import { connectToServer, firstPage, serverHeaders } from './lib/transport';
import type { Session } from './lib/types';
import './app.css';

const root = document.getElementById('root')!;

/** Start over at the app's home, e.g. after signing in or out. */
function restart(): void {
    window.location.replace('/');
}

/** Sign out: the server forgets the token (when it can be reached), and the keychain forgets the session. */
async function signOut(session: Session): Promise<void> {
    await fetch(new URL('/api/v1/desktop/token', session.server), {
        method: 'DELETE',
        headers: { ...serverHeaders(), Accept: 'application/json' },
        credentials: 'omit',
    }).catch(() => {});
    await clearSession().catch(() => {});
    restart();
}

function render(screen: React.ReactNode): void {
    createRoot(root).render(
        <StrictMode>
            <TooltipProvider delayDuration={0}>{screen}</TooltipProvider>
        </StrictMode>,
    );
}

/**
 * Signed in: the web app's own pages (DESK-001), talking to the server over HTTP with the token. Signed out: the
 * app's sign-in.
 */
async function boot(): Promise<void> {
    initializeTheme();

    const session = await loadSession().catch(() => null);

    if (!session) {
        render(<SignIn lastServer={null} onSignedIn={restart} />);

        return;
    }

    connectToServer(session, () => void signOut(session));

    let page;

    try {
        page = await firstPage();
    } catch (error) {
        render(
            <Unreachable
                server={session.server}
                message={(error as Error).message}
                onRetry={restart}
                onSignOut={() => void signOut(session)}
            />,
        );

        return;
    }

    // The server no longer takes the token (signed out elsewhere, or revoked in Settings → Desktop app).
    if (!page) {
        await clearSession().catch(() => {});
        render(<SignIn lastServer={session.server} onSignedIn={restart} />);

        return;
    }

    root.remove();
    await provideNotifications().catch(() => {});
    startApp({ page });
}

void boot();
