import {
    Check,
    ExternalLink,
    GitBranch,
    Loader2,
    Lock,
    Plus,
    RefreshCw,
    Search,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import GitHubAppController from '@/actions/App/Http/Controllers/GitHubAppController';
import SocialProviderIcon from '@/components/social-provider-icon';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

export type GitHubInstallation = {
    id: number;
    account: string;
    type: string;
    avatar_url: string | null;
    selection: string | null;
    can_create: boolean;
    manage_url: string;
};

export type GitHubInfo = {
    configured: boolean;
    suggested_name?: string;
    problems: string[];
    settings_url: string | null;
    /** For admins: where GitHub must send people back to (the app's Callback and Setup URL). */
    callback_url: string | null;
    signed_in: boolean;
    login: string | null;
    connect_url: string | null;
    reconnect_url: string | null;
    installations: GitHubInstallation[];
};

type Repository = {
    full_name: string;
    name: string;
    private: boolean;
    default_branch: string;
    html_url: string;
    pushed_at: string | null;
    empty: boolean;
};

/** Set when the user leaves for GitHub, so coming back without GitHub's redirect can pick up where they left off. */
const startedKey = (projectId: number) => `github-connect-started:${projectId}`;

function readStarted(projectId: number): boolean {
    try {
        return window.sessionStorage.getItem(startedKey(projectId)) !== null;
    } catch {
        return false;
    }
}

function writeStarted(projectId: number, started: boolean): void {
    try {
        if (started) {
            window.sessionStorage.setItem(startedKey(projectId), '1');
        } else {
            window.sessionStorage.removeItem(startedKey(projectId));
        }
    } catch {
        // Private mode without storage: the dialog just starts from the beginning.
    }
}

type RequestError = Error & { status?: number; data?: { reconnect?: boolean } };

/** Send a request; a lapsed GitHub sign-in (401 with `reconnect`) calls onSignedOut instead of failing loudly. */
function useGitHubRequest(onSignedOut: () => void) {
    return useCallback(
        <T,>(url: string, body?: unknown, method?: 'POST' | 'PUT') =>
            jsonRequest<T>(url, body, method).catch((e: RequestError) => {
                if (e.status === 401 && e.data?.reconnect) {
                    onSignedOut();
                }

                throw e;
            }),
        [onSignedOut],
    );
}

/**
 * "Connect to GitHub": a dialog that walks through installing the GitHub App (once), then creating a new
 * repository or picking an existing one, for the owner the user chooses.
 */
export function GitHubConnect({
    projectId,
    github,
    hasCommits,
    autoOpen,
    onConnected,
    onOtherHost,
}: {
    projectId: number;
    github: GitHubInfo;
    hasCommits: boolean;
    /** Back from GitHub: open straight away. */
    autoOpen: boolean;
    /** The remote changed: reload the panel. */
    onConnected: () => void;
    onOtherHost: () => void;
}) {
    // Back without GitHub's redirect (it isn't set up to send people back, or they used the back button):
    // reopen, offering to finish rather than start over. A proper return (autoOpen) clears that.
    const [cameBack] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        const started = readStarted(projectId);

        if (autoOpen || github.installations.length > 0) {
            writeStarted(projectId, false);

            return false;
        }

        return started;
    });
    const [open, setOpen] = useState(autoOpen || cameBack);

    return (
        <div data-test="git-github-app">
            <div className="flex items-start gap-3">
                <SocialProviderIcon
                    provider="github"
                    className="mt-0.5 size-5 shrink-0"
                />
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">
                        Put this project on GitHub
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {hasCommits
                            ? 'Create a repository for it, or connect one you have, then push and pull from here.'
                            : 'Bring in a repository you have, or create a new one to push to.'}
                    </p>
                </div>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                <Button
                    size="sm"
                    onClick={() => setOpen(true)}
                    data-test="git-connect-github"
                >
                    <SocialProviderIcon provider="github" className="size-4" />
                    Connect to GitHub
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={onOtherHost}
                    data-test="git-connect-existing"
                >
                    Other git host
                </Button>
            </div>
            {open && (
                <GitHubDialog
                    projectId={projectId}
                    github={github}
                    hasCommits={hasCommits}
                    cameBack={cameBack}
                    onClose={() => setOpen(false)}
                    onConnected={() => {
                        setOpen(false);
                        onConnected();
                    }}
                />
            )}
        </div>
    );
}

function GitHubDialog({
    projectId,
    github,
    hasCommits,
    cameBack,
    onClose,
    onConnected,
}: {
    projectId: number;
    github: GitHubInfo;
    hasCommits: boolean;
    cameBack: boolean;
    onClose: () => void;
    onConnected: () => void;
}) {
    const [signedOut, setSignedOut] = useState(!github.signed_in);
    const [ownerId, setOwnerId] = useState<number | null>(
        github.installations[0]?.id ?? null,
    );
    const [mode, setMode] = useState<'new' | 'existing'>(
        hasCommits ? 'new' : 'existing',
    );
    const request = useGitHubRequest(useCallback(() => setSignedOut(true), []));
    const owner = github.installations.find((i) => i.id === ownerId) ?? null;
    const needsInstall = signedOut || github.installations.length === 0;

    return (
        <Dialog open onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent
                className="sm:max-w-xl"
                data-test="git-github-dialog"
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <SocialProviderIcon
                            provider="github"
                            className="size-5"
                        />
                        Connect to GitHub
                    </DialogTitle>
                    {!needsInstall && github.login && (
                        <DialogDescription>
                            Signed in to GitHub as @{github.login}.
                        </DialogDescription>
                    )}
                </DialogHeader>

                {needsInstall ? (
                    <InstallStep
                        projectId={projectId}
                        github={github}
                        reconnect={signedOut && github.signed_in}
                        installed={github.installations.length > 0}
                        cameBack={cameBack}
                    />
                ) : (
                    <div className="space-y-4">
                        <OwnerPicker
                            installations={github.installations}
                            ownerId={ownerId}
                            onChange={setOwnerId}
                            addUrl={github.connect_url}
                        />

                        <div
                            role="tablist"
                            className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1"
                        >
                            {(
                                [
                                    ['new', 'New repository'],
                                    ['existing', 'Existing repository'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="tab"
                                    aria-selected={mode === value}
                                    onClick={() => setMode(value)}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-sm',
                                        mode === value
                                            ? 'bg-background font-medium shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                    data-test={`git-github-tab-${value}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        {owner &&
                            (mode === 'new' ? (
                                <NewRepository
                                    key={owner.id}
                                    projectId={projectId}
                                    owner={owner}
                                    suggestedName={
                                        github.suggested_name ?? 'my-app'
                                    }
                                    request={request}
                                    onConnected={onConnected}
                                    onUseExisting={() => setMode('existing')}
                                />
                            ) : (
                                <ExistingRepository
                                    key={owner.id}
                                    projectId={projectId}
                                    owner={owner}
                                    hasCommits={hasCommits}
                                    request={request}
                                    onConnected={onConnected}
                                />
                            ))}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** The first visit (or a lapsed sign-in): what happens on GitHub, and the button to go there. */
function InstallStep({
    projectId,
    github,
    reconnect,
    installed,
    cameBack,
}: {
    projectId: number;
    github: GitHubInfo;
    reconnect: boolean;
    installed: boolean;
    cameBack: boolean;
}) {
    const leave = () => writeStarted(projectId, true);
    const adminNote = github.callback_url && (
        <p
            className="rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground"
            data-test="git-github-admin-note"
        >
            Admins: GitHub sends people back only if the app's{' '}
            <strong>Callback URL</strong> and <strong>Setup URL</strong> are{' '}
            <code className="font-mono break-all text-foreground">
                {github.callback_url}
            </code>
            , with “Redirect on update” and “Request user authorization (OAuth)
            during installation” on.
        </p>
    );

    if (cameBack && !reconnect) {
        return (
            <div className="space-y-4" data-test="git-github-came-back">
                <p className="text-sm">Finished installing on GitHub?</p>
                <p className="text-sm text-muted-foreground">
                    GitHub didn't bring you back here. If you've installed the
                    app, continue and OneDrop picks it up. It asks GitHub to
                    confirm it's you, which only takes a moment.
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button asChild data-test="git-github-finish">
                        <a href={github.reconnect_url ?? '#'} onClick={leave}>
                            Continue
                            <ExternalLink className="size-4" />
                        </a>
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={github.connect_url ?? '#'} onClick={leave}>
                            Install on GitHub again
                        </a>
                    </Button>
                </div>
                {adminNote}
            </div>
        );
    }

    if (reconnect) {
        return (
            <div className="space-y-4" data-test="git-github-reconnect">
                <p className="text-sm text-muted-foreground">
                    Your GitHub sign-in has expired. Sign in again to see your
                    repositories. It only takes a moment.
                </p>
                <Button asChild>
                    <a href={github.reconnect_url ?? '#'} onClick={leave}>
                        Reconnect GitHub
                        <ExternalLink className="size-4" />
                    </a>
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-4" data-test="git-github-install">
            <ol className="space-y-3 text-sm">
                {[
                    installed
                        ? "Sign in to GitHub so OneDrop can see which accounts it's installed on."
                        : 'Install the OneDrop app on your GitHub account or an organization.',
                    'Choose all repositories, or only the ones OneDrop may use. You can change this later.',
                    'GitHub brings you back here to pick or create a repository.',
                ].map((text, index) => (
                    <li key={text} className="flex gap-3">
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                            {index + 1}
                        </span>
                        <span className="pt-0.5">{text}</span>
                    </li>
                ))}
            </ol>
            <p className="text-xs text-muted-foreground">
                OneDrop can only read and write the code in the repositories you
                choose. It never gets your password, and no token goes into your
                app's sandbox.
            </p>
            <Button asChild data-test="git-github-continue">
                <a href={github.connect_url ?? '#'} onClick={leave}>
                    Continue to GitHub
                    <ExternalLink className="size-4" />
                </a>
            </Button>
            {adminNote}
        </div>
    );
}

function OwnerPicker({
    installations,
    ownerId,
    onChange,
    addUrl,
}: {
    installations: GitHubInstallation[];
    ownerId: number | null;
    onChange: (id: number) => void;
    addUrl: string | null;
}) {
    return (
        <div>
            <p className="mb-1.5 text-xs font-medium text-muted-foreground">
                Owner
            </p>
            <div className="flex flex-wrap gap-2" data-test="git-github-owners">
                {installations.map((installation) => (
                    <button
                        key={installation.id}
                        type="button"
                        onClick={() => onChange(installation.id)}
                        aria-pressed={installation.id === ownerId}
                        className={cn(
                            'flex items-center gap-2 rounded-full border py-1 pr-3 pl-1 text-sm',
                            installation.id === ownerId
                                ? 'border-primary bg-primary/5 font-medium'
                                : 'border-sidebar-border/70 hover:bg-muted dark:border-sidebar-border',
                        )}
                        data-test="git-github-owner"
                    >
                        <Avatar installation={installation} />
                        {installation.account}
                    </button>
                ))}
                <a
                    href={addUrl ?? '#'}
                    className="flex items-center gap-1.5 rounded-full border border-dashed border-sidebar-border px-3 py-1 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                    data-test="git-github-add-owner"
                >
                    <Plus className="size-3.5" />
                    Add account or organization
                </a>
            </div>
        </div>
    );
}

function Avatar({ installation }: { installation: GitHubInstallation }) {
    return installation.avatar_url ? (
        <img
            src={installation.avatar_url}
            alt=""
            className={cn(
                'size-6',
                installation.type === 'Organization'
                    ? 'rounded-md'
                    : 'rounded-full',
            )}
        />
    ) : (
        <span className="flex size-6 items-center justify-center rounded-full bg-muted text-xs font-medium uppercase">
            {installation.account.slice(0, 1)}
        </span>
    );
}

type GitHubRequest = <T>(
    url: string,
    body?: unknown,
    method?: 'POST' | 'PUT',
) => Promise<T>;

/** Calls `refresh` when the user comes back to this tab (e.g. from GitHub), and every few seconds while `polling`. */
function useRefreshOnReturn(refresh: () => void, polling = false) {
    const latest = useRef(refresh);
    latest.current = refresh;

    useEffect(() => {
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                latest.current();
            }
        };

        window.addEventListener('focus', onVisible);
        document.addEventListener('visibilitychange', onVisible);
        const timer = polling
            ? setInterval(() => latest.current(), 4000)
            : null;

        return () => {
            window.removeEventListener('focus', onVisible);
            document.removeEventListener('visibilitychange', onVisible);

            if (timer) {
                clearInterval(timer);
            }
        };
    }, [polling]);
}

const NAME_PATTERN = /^[A-Za-z0-9._-]+$/;

function NewRepository({
    projectId,
    owner,
    suggestedName,
    request,
    onConnected,
    onUseExisting,
}: {
    projectId: number;
    owner: GitHubInstallation;
    suggestedName: string;
    request: GitHubRequest;
    onConnected: () => void;
    onUseExisting: () => void;
}) {
    const [name, setName] = useState(suggestedName);
    const [isPrivate, setIsPrivate] = useState(true);
    const [availability, setAvailability] = useState<
        'checking' | 'available' | 'taken' | 'invalid' | null
    >(null);
    const [waiting, setWaiting] = useState(false);
    const [found, setFound] = useState<Repository | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const trimmed = name.trim();

    // Check the name as it's typed.
    useEffect(() => {
        if (waiting) {
            return;
        }

        if (
            trimmed === '' ||
            !NAME_PATTERN.test(trimmed) ||
            trimmed === '.' ||
            trimmed === '..'
        ) {
            setAvailability(trimmed === '' ? null : 'invalid');

            return;
        }

        setAvailability('checking');
        let cancelled = false;
        const timer = setTimeout(() => {
            request<{ available: boolean }>(
                GitHubAppController.availability.url(projectId, {
                    query: { installation_id: owner.id, name: trimmed },
                }),
            )
                .then(
                    ({ available }) =>
                        !cancelled &&
                        setAvailability(available ? 'available' : 'taken'),
                )
                .catch(() => !cancelled && setAvailability(null));
        }, 350);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [trimmed, owner.id, projectId, request, waiting]);

    // Made on GitHub: look for it each time the user comes back, and every few seconds.
    const look = useCallback(() => {
        request<{ repositories: Repository[] }>(
            GitHubAppController.repositories.url(projectId, {
                query: { installation_id: owner.id },
            }),
        )
            .then(({ repositories }) => {
                const match = repositories.find(
                    (repository) =>
                        repository.name.toLowerCase() === trimmed.toLowerCase(),
                );

                if (match) {
                    setFound(match);
                }
            })
            .catch(() => undefined);
    }, [owner.id, projectId, request, trimmed]);

    useRefreshOnReturn(() => waiting && !found && look(), waiting && !found);

    const create = () => {
        setBusy(true);
        setError(null);
        request(GitHubAppController.create.url(projectId), {
            installation_id: owner.id,
            name: trimmed,
            private: isPrivate,
        })
            .then(onConnected)
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(false));
    };

    const connectFound = () => {
        if (!found) {
            return;
        }

        setBusy(true);
        setError(null);
        request(
            GitHubAppController.connect.url(projectId),
            { installation_id: owner.id, repository: found.full_name },
            'PUT',
        )
            .then(onConnected)
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(false));
    };

    const newUrl = `https://github.com/new?${new URLSearchParams({
        owner: owner.account,
        name: trimmed,
        visibility: isPrivate ? 'private' : 'public',
    })}`;

    if (waiting) {
        return (
            <div className="space-y-4" data-test="git-github-waiting">
                {found ? (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-500/5 p-3 text-sm">
                        <Check className="size-4 text-green-600" />
                        <span className="flex-1">
                            Found <strong>{found.full_name}</strong>
                        </span>
                    </div>
                ) : (
                    <div className="flex items-start gap-3 rounded-lg border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
                        <Loader2 className="mt-0.5 size-4 animate-spin text-muted-foreground" />
                        <div className="space-y-2">
                            <p>
                                Waiting for{' '}
                                <strong>
                                    {owner.account}/{trimmed}
                                </strong>
                                . Create it in the GitHub tab, then come back
                                here.
                            </p>
                            {owner.selection === 'selected' && (
                                <p className="text-muted-foreground">
                                    OneDrop only reaches the repositories you
                                    chose, so after creating it,{' '}
                                    <a
                                        href={owner.manage_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline underline-offset-4"
                                    >
                                        give OneDrop access to it
                                    </a>
                                    .
                                </p>
                            )}
                        </div>
                    </div>
                )}
                {error && <p className="text-sm text-red-600">{error}</p>}
                <div className="flex flex-wrap gap-2">
                    <Button
                        disabled={!found || busy}
                        onClick={connectFound}
                        data-test="git-github-connect-found"
                    >
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        Connect and push
                    </Button>
                    {!found && (
                        <>
                            <Button variant="outline" asChild>
                                <a
                                    href={newUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Open GitHub again
                                    <ExternalLink className="size-4" />
                                </a>
                            </Button>
                            <Button variant="ghost" onClick={look}>
                                <RefreshCw className="size-4" />
                                Check now
                            </Button>
                        </>
                    )}
                    <Button
                        variant="ghost"
                        onClick={() => {
                            setWaiting(false);
                            setFound(null);
                        }}
                    >
                        Back
                    </Button>
                </div>
            </div>
        );
    }

    const canSubmit = availability === 'available' && !busy;

    return (
        <div className="space-y-4" data-test="git-github-new">
            <label className="block space-y-1.5 text-sm">
                <span className="font-medium">Repository name</span>
                <div className="flex items-center rounded-md border border-input focus-within:ring-2 focus-within:ring-ring">
                    <span className="border-r border-input px-3 py-2 text-muted-foreground">
                        {owner.account} /
                    </span>
                    <input
                        autoFocus
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        className="min-w-0 flex-1 bg-transparent px-3 py-2 outline-none"
                        data-test="git-github-name"
                    />
                </div>
                <span
                    className="block min-h-5 text-xs"
                    data-test="git-github-availability"
                >
                    {availability === 'checking' && (
                        <span className="text-muted-foreground">Checking…</span>
                    )}
                    {availability === 'available' && (
                        <span className="text-green-600">
                            <Check className="mr-1 inline size-3.5" />
                            {trimmed} is available.
                        </span>
                    )}
                    {availability === 'taken' && (
                        <span className="text-red-600">
                            <X className="mr-1 inline size-3.5" />
                            {owner.account} already has {trimmed}.{' '}
                            <button
                                type="button"
                                onClick={onUseExisting}
                                className="underline underline-offset-4"
                            >
                                Connect it instead
                            </button>
                        </span>
                    )}
                    {availability === 'invalid' && (
                        <span className="text-red-600">
                            Use letters, numbers, dots, dashes and underscores.
                        </span>
                    )}
                </span>
            </label>

            <fieldset className="grid grid-cols-2 gap-2">
                <legend className="sr-only">Visibility</legend>
                {(
                    [
                        [true, 'Private', 'Only you and people you invite'],
                        [false, 'Public', 'Anyone on the internet can see it'],
                    ] as const
                ).map(([value, label, hint]) => (
                    <button
                        key={label}
                        type="button"
                        onClick={() => setIsPrivate(value)}
                        aria-pressed={isPrivate === value}
                        className={cn(
                            'rounded-lg border p-3 text-left text-sm',
                            isPrivate === value
                                ? 'border-primary bg-primary/5'
                                : 'border-sidebar-border/70 hover:bg-muted dark:border-sidebar-border',
                        )}
                        data-test={`git-github-${label.toLowerCase()}`}
                    >
                        <span className="flex items-center gap-1.5 font-medium">
                            {value && <Lock className="size-3.5" />}
                            {label}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {hint}
                        </span>
                    </button>
                ))}
            </fieldset>

            {error && <p className="text-sm text-red-600">{error}</p>}

            {owner.can_create ? (
                <Button
                    className="w-full"
                    disabled={!canSubmit}
                    onClick={create}
                    data-test="git-github-create"
                >
                    {busy && <Loader2 className="size-4 animate-spin" />}
                    Create and push
                </Button>
            ) : (
                <div className="space-y-2">
                    <Button
                        className="w-full"
                        disabled={!canSubmit}
                        asChild={canSubmit}
                    >
                        {canSubmit ? (
                            <a
                                href={newUrl}
                                target="_blank"
                                rel="noreferrer"
                                onClick={() => setWaiting(true)}
                                data-test="git-github-create-on-github"
                            >
                                Create it on GitHub
                                <ExternalLink className="size-4" />
                            </a>
                        ) : (
                            <span data-test="git-github-create-on-github">
                                Create it on GitHub
                            </span>
                        )}
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        {owner.type === 'Organization'
                            ? "OneDrop isn't allowed to create repositories here, so GitHub opens with this one filled in."
                            : "GitHub doesn't let apps create repositories on personal accounts, so GitHub opens with this one filled in."}{' '}
                        Create it, come back, and OneDrop connects and pushes to
                        it.
                    </p>
                </div>
            )}
        </div>
    );
}

function ExistingRepository({
    projectId,
    owner,
    hasCommits,
    request,
    onConnected,
}: {
    projectId: number;
    owner: GitHubInstallation;
    hasCommits: boolean;
    request: GitHubRequest;
    onConnected: () => void;
}) {
    const [repositories, setRepositories] = useState<Repository[] | null>(null);
    const [filter, setFilter] = useState('');
    const [chosen, setChosen] = useState<Repository | null>(null);
    const [branches, setBranches] = useState<string[] | null>(null);
    const [branch, setBranch] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(() => {
        request<{ repositories: Repository[] }>(
            GitHubAppController.repositories.url(projectId, {
                query: { installation_id: owner.id },
            }),
        )
            .then(({ repositories }) => {
                setRepositories(repositories);
                setError(null);
            })
            .catch((e: Error) => setError(e.message));
    }, [owner.id, projectId, request]);

    useEffect(load, [load]);
    // Back from choosing repositories on GitHub: show the new list.
    useRefreshOnReturn(load);

    const choose = (repository: Repository) => {
        setChosen(repository);
        setBranch(repository.default_branch);
        setBranches(null);

        if (repository.empty) {
            return;
        }

        request<{ branches: string[] }>(
            GitHubAppController.branches.url(projectId, {
                query: {
                    installation_id: owner.id,
                    repository: repository.full_name,
                },
            }),
        )
            .then(({ branches }) => setBranches(branches))
            .catch((e: Error) => setError(e.message));
    };

    const connect = () => {
        if (!chosen) {
            return;
        }

        setBusy(true);
        setError(null);
        request(
            GitHubAppController.connect.url(projectId),
            { installation_id: owner.id, repository: chosen.full_name, branch },
            'PUT',
        )
            .then(onConnected)
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(false));
    };

    const shown = (repositories ?? []).filter((repository) =>
        repository.name.toLowerCase().includes(filter.trim().toLowerCase()),
    );

    const action = !chosen
        ? 'Connect'
        : !hasCommits
          ? chosen.empty
              ? 'Connect'
              : 'Connect and bring it in'
          : chosen.empty
            ? 'Connect and push'
            : 'Connect';

    return (
        <div className="space-y-3" data-test="git-github-existing">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    autoFocus
                    value={filter}
                    onChange={(event) => setFilter(event.target.value)}
                    placeholder={`Search ${owner.account}'s repositories`}
                    className="pl-8"
                    data-test="git-github-filter"
                />
            </div>

            <ul
                className="max-h-60 min-h-24 divide-y overflow-y-auto rounded-lg border border-sidebar-border/70 dark:border-sidebar-border"
                data-test="git-github-repositories"
            >
                {repositories === null ? (
                    <li className="flex items-center gap-2 px-3 py-3 text-sm text-muted-foreground">
                        {error ?? (
                            <>
                                <Loader2 className="size-4 animate-spin" />
                                Loading repositories…
                            </>
                        )}
                    </li>
                ) : shown.length === 0 ? (
                    <li className="px-3 py-3 text-sm text-muted-foreground">
                        {repositories.length === 0
                            ? `OneDrop can't reach any of ${owner.account}'s repositories yet.`
                            : 'No repositories match.'}
                    </li>
                ) : (
                    shown.map((repository) => (
                        <li key={repository.full_name}>
                            <button
                                type="button"
                                onClick={() => choose(repository)}
                                aria-pressed={
                                    chosen?.full_name === repository.full_name
                                }
                                className={cn(
                                    'flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-muted/50',
                                    chosen?.full_name ===
                                        repository.full_name && 'bg-muted',
                                )}
                                data-test="git-github-repository"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">
                                        {repository.name}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {repository.empty
                                            ? 'Empty'
                                            : repository.pushed_at
                                              ? `Updated ${timeAgo(repository.pushed_at)}`
                                              : ''}
                                    </span>
                                </span>
                                <Badge>
                                    {repository.private ? 'Private' : 'Public'}
                                </Badge>
                                {chosen?.full_name === repository.full_name && (
                                    <Check className="size-4 text-primary" />
                                )}
                            </button>
                        </li>
                    ))
                )}
            </ul>

            <p className="text-xs text-muted-foreground">
                Don't see it?{' '}
                <a
                    href={owner.manage_url}
                    target="_blank"
                    rel="noreferrer"
                    className="underline underline-offset-4"
                    data-test="git-github-manage"
                >
                    Choose which repositories OneDrop can reach
                </a>
                . The list updates when you come back.
            </p>

            {error && repositories !== null && (
                <p className="text-sm text-red-600">{error}</p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {chosen && !chosen.empty && (
                    <label className="flex items-center gap-2 text-sm">
                        <GitBranch className="size-4 text-muted-foreground" />
                        <select
                            value={branch}
                            onChange={(event) => setBranch(event.target.value)}
                            disabled={branches === null}
                            className="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                            data-test="git-github-branch"
                        >
                            {(branches ?? [branch]).map((name) => (
                                <option key={name} value={name}>
                                    {name}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                <div className="flex-1" />
                <Button
                    disabled={!chosen || busy}
                    onClick={connect}
                    data-test="git-github-connect"
                >
                    {busy && <Loader2 className="size-4 animate-spin" />}
                    {action}
                </Button>
            </div>
        </div>
    );
}

function Badge({ children }: { children: ReactNode }) {
    return (
        <span className="rounded-full border border-sidebar-border/70 px-2 py-0.5 text-xs text-muted-foreground dark:border-sidebar-border">
            {children}
        </span>
    );
}

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

function timeAgo(iso: string): string {
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return RELATIVE.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}
