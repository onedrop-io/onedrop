import { router } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    ChevronLeft,
    ChevronRight,
    Copy,
    Download,
    EllipsisVertical,
    Eye,
    ExternalLink,
    KeyRound,
    LogOut,
    Mail,
    Pencil,
    RefreshCw,
    KeySquare,
    Search,
    Settings,
    Shield,
    Trash2,
    UserCheck,
    UserPlus,
    UserX,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { toast } from 'sonner';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useClipboard } from '@/hooks/use-clipboard';
import { authApi } from '@/lib/auth-api';
import { cn } from '@/lib/utils';
import type {
    AppAuthMethod,
    AppAuthProvider,
    AppAuthStatus,
    AppOneDrop,
    AppUser,
    AppUserCapabilities,
    AppUsersPage,
} from '@/types';

type Configured = Extract<AppAuthStatus, { configured: true }>;

const METHODS: {
    id: AppAuthMethod;
    label: string;
    icon: (props: { className?: string }) => ReactNode;
}[] = [
    { id: 'password', label: 'Email and password', icon: Mail },
    { id: 'google', label: 'Google', icon: GoogleMark },
    { id: 'github', label: 'GitHub', icon: GitHubMark },
    { id: 'microsoft', label: 'Microsoft', icon: MicrosoftMark },
    { id: 'onedrop', label: 'OneDrop accounts', icon: OneDropMark },
];

/** Where to create each provider's OAuth app. */
const CONSOLES: Record<AppAuthProvider['id'], string> = {
    google: 'https://console.cloud.google.com/apis/credentials',
    github: 'https://github.com/settings/developers',
    microsoft:
        'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
};

/**
 * Sign-in for the app being built. The agent adds it to the app itself (users live in the app's
 * own database); this panel starts that, lists the users, and holds the provider keys.
 */
export default function AuthPanel({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    working: boolean;
}) {
    const [status, setStatus] = useState<AppAuthStatus | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [tab, setTab] = useState<'users' | 'configure'>('users');

    const load = useCallback(() => {
        setLoading(true);
        authApi
            .status(projectId)
            .then((next) => {
                setStatus(next);
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [projectId]);

    // Reload when the sandbox starts and whenever the agent finishes a run (it may have changed sign-in).
    useEffect(() => {
        if (running && !working) {
            load();
        }
    }, [running, working, load]);

    if (!running) {
        return <Empty>Users & Auth works when the sandbox is running.</Empty>;
    }

    if (error) {
        return (
            <Empty tone="error">
                {error}
                <Button
                    size="sm"
                    variant="outline"
                    className="mx-auto mt-4 flex"
                    onClick={load}
                >
                    <RefreshCw /> Try again
                </Button>
            </Empty>
        );
    }

    if (!status) {
        return <Empty>Checking your app's sign-in…</Empty>;
    }

    if (!status.configured) {
        return (
            <SetUp
                projectId={projectId}
                working={working}
                loading={loading}
                onCheck={load}
            />
        );
    }

    return (
        <div className="max-w-4xl" data-test="auth-panel">
            <div
                role="tablist"
                aria-label="Users & Auth"
                className="flex gap-1 border-b border-sidebar-border/70 dark:border-sidebar-border"
            >
                {(
                    [
                        { id: 'users', label: 'Users', icon: Users },
                        { id: 'configure', label: 'Configure', icon: Settings },
                    ] as const
                ).map(({ id, label, icon: Icon }) => (
                    <button
                        key={id}
                        type="button"
                        role="tab"
                        aria-selected={tab === id}
                        onClick={() => setTab(id)}
                        data-test={`auth-tab-${id}`}
                        className={cn(
                            '-mb-px flex items-center gap-1.5 border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground hover:text-foreground',
                            tab === id && 'border-foreground text-foreground',
                        )}
                    >
                        <Icon className="size-4" />
                        {label}
                    </button>
                ))}
            </div>

            <div className="pt-5">
                {tab === 'users' ? (
                    <UsersList projectId={projectId} status={status} />
                ) : (
                    <Configure
                        projectId={projectId}
                        status={status}
                        working={working}
                        onChange={setStatus}
                    />
                )}
            </div>
        </div>
    );
}

/** Before sign-in exists: choose methods and hand it to the agent. */
function SetUp({
    projectId,
    working,
    loading,
    onCheck,
}: {
    projectId: number;
    working: boolean;
    loading: boolean;
    onCheck: () => void;
}) {
    const [methods, setMethods] = useState<AppAuthMethod[]>(['password']);
    const [sent, setSent] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const send = () => {
        setSending(true);
        authApi
            .setup(projectId, methods)
            .then(() => {
                setSent(true);
                setError(null);
                router.reload();
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setSending(false));
    };

    return (
        <div
            className="mx-auto max-w-lg rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border"
            data-test="auth-setup"
        >
            <span className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted">
                <UserCheck className="size-5" />
            </span>
            <h3 className="mt-4 text-lg font-medium">
                Let people sign in to your app
            </h3>
            <p className="mt-2 text-sm text-muted-foreground">
                The agent adds sign-up and sign-in pages to your app. Accounts
                are kept in your app's own database, so they're yours and
                nothing is locked in.
            </p>

            {sent ? (
                <div className="mt-6 space-y-3" data-test="auth-setup-sent">
                    <p className="text-sm">
                        {working
                            ? 'The agent is adding sign-in. Follow along in the chat; this updates when it’s done.'
                            : 'Asked the agent. Once it has finished, check again.'}
                    </p>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={onCheck}
                        disabled={loading}
                    >
                        <RefreshCw className={cn(loading && 'animate-spin')} />{' '}
                        Check again
                    </Button>
                </div>
            ) : (
                <>
                    <fieldset className="mt-6 grid grid-cols-2 gap-2 text-left">
                        <legend className="mb-2 text-sm font-medium">
                            How can people sign in?
                        </legend>
                        {METHODS.map(({ id, label, icon: Icon }) => {
                            const checked = methods.includes(id);

                            return (
                                <label
                                    key={id}
                                    data-test={`auth-method-${id}`}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2 rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border',
                                        checked &&
                                            'border-foreground/40 bg-muted',
                                    )}
                                >
                                    <input
                                        type="checkbox"
                                        className="sr-only"
                                        checked={checked}
                                        onChange={() =>
                                            setMethods((current) =>
                                                checked
                                                    ? current.filter(
                                                          (m) => m !== id,
                                                      )
                                                    : [...current, id],
                                            )
                                        }
                                    />
                                    <Icon className="size-4 shrink-0" />
                                    <span className="flex-1">{label}</span>
                                    {checked && <Check className="size-4" />}
                                </label>
                            );
                        })}
                    </fieldset>
                    {methods.some(
                        (m) => m !== 'password' && m !== 'onedrop',
                    ) && (
                        <p className="mt-2 text-left text-xs text-muted-foreground">
                            Google, GitHub and Microsoft need keys from the
                            provider. You add them here afterwards; we'll show
                            you where to get them.
                        </p>
                    )}
                    {error && (
                        <p className="mt-3 text-sm text-red-600">{error}</p>
                    )}
                    <Button
                        className="mt-6"
                        onClick={send}
                        disabled={methods.length === 0 || sending}
                        data-test="auth-setup-send"
                    >
                        Set up with agent <ArrowRight />
                    </Button>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Sends a message to the agent in the chat
                        {working ? '; it runs after the current one.' : '.'}
                    </p>
                </>
            )}
        </div>
    );
}

function UsersList({
    projectId,
    status,
}: {
    projectId: number;
    status: Configured;
}) {
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<AppUsersPage | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [dialog, setDialog] = useState<UserDialogState | null>(null);

    // Search as the user types, without a request per keystroke.
    useEffect(() => {
        const timer = setTimeout(() => {
            setQuery(search.trim());
            setPage(1);
        }, 250);

        return () => clearTimeout(timer);
    }, [search]);

    const load = useCallback(() => {
        setLoading(true);
        authApi
            .users(projectId, query, page)
            .then((next) => {
                setResult(next);
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [projectId, query, page]);

    useEffect(load, [load]);

    const capabilities = result?.capabilities ?? {
        helper: status.can_create,
        disable: true,
        require_password_change: true,
        roles: true,
        sign_in_as: false,
    };
    const roles = result?.roles ?? status.roles;

    /** Open the app as this user in a new tab (opened now, so it isn't blocked as a pop-up). */
    const signInAs = (user: AppUser) => {
        const tab = window.open('about:blank', '_blank');

        authApi
            .signInAs(projectId, String(user.id))
            .then((url) => {
                if (tab) {
                    tab.location.href = url;
                } else {
                    window.location.href = url;
                }
            })
            .catch((e: Error) => {
                tab?.close();
                toast.error(e.message);
            });
    };

    /** Run a one-click action (turn off, sign out, ...), then refresh the list. */
    const act = (
        request: Promise<unknown>,
        done: string,
        testId = 'auth-action-done',
    ) => {
        const id = toast.loading('Saving…');

        request
            .then(() => {
                toast.success(done, { id, className: testId });
                load();
            })
            .catch((e: Error) => toast.error(e.message, { id }));
    };

    const pages = result
        ? Math.max(1, Math.ceil(result.total / result.per_page))
        : 1;

    return (
        // Laid out by the panel's own width: a narrow pane drops the Joined column.
        <div className="@container space-y-3">
            <div className="flex items-center gap-2">
                <label className="flex h-9 w-full max-w-xs items-center gap-2 rounded-md border border-input px-3">
                    <Search className="size-4 text-muted-foreground" />
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search by name or email"
                        className="min-w-0 flex-1 bg-transparent text-sm outline-none"
                        aria-label="Search users"
                        data-test="auth-users-search"
                    />
                </label>
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label="Refresh users"
                    onClick={load}
                >
                    <RefreshCw className={cn(loading && 'animate-spin')} />
                </Button>
                {result && (
                    <span
                        className="ml-auto text-sm whitespace-nowrap text-muted-foreground"
                        data-test="auth-users-count"
                    >
                        {result.total === 1
                            ? '1 user'
                            : `${result.total} users`}
                    </span>
                )}
                <Button
                    size="sm"
                    variant="outline"
                    className={cn(!result && 'ml-auto')}
                    asChild
                >
                    <a
                        href={authApi.exportUrl(projectId)}
                        download
                        data-test="auth-export-users"
                    >
                        <Download /> Export
                    </a>
                </Button>
                <Button
                    size="sm"
                    onClick={() =>
                        setDialog(
                            capabilities.helper
                                ? { kind: 'create' }
                                : { kind: 'upgrade', capabilities },
                        )
                    }
                    data-test="auth-add-user"
                >
                    <UserPlus /> Add user
                </Button>
            </div>

            {dialog && (
                <UserDialogs
                    projectId={projectId}
                    state={dialog}
                    onClose={() => setDialog(null)}
                    onSaved={() => {
                        setDialog(null);
                        load();
                    }}
                />
            )}

            {error ? (
                <Empty tone="error">{error}</Empty>
            ) : (
                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm" data-test="auth-users">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Email</th>
                                {capabilities.roles && (
                                    <th className="px-3 py-2 font-medium">
                                        Role
                                    </th>
                                )}
                                <th className="hidden px-3 py-2 font-medium @3xl:table-cell">
                                    Joined
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Last signed in
                                </th>
                                <th className="w-px px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {result?.users.map((user, index) => (
                                <tr
                                    key={String(user.id ?? index)}
                                    className={cn(
                                        'border-t border-sidebar-border/70 dark:border-sidebar-border',
                                        user.disabled_at !== null &&
                                            'text-muted-foreground',
                                    )}
                                    data-test="auth-user"
                                >
                                    <td className="px-3 py-2">
                                        <span className="flex flex-wrap items-center gap-1.5">
                                            {user.name ?? '—'}
                                            {user.disabled_at !== null && (
                                                <UserBadge
                                                    tone="muted"
                                                    testId="auth-user-disabled"
                                                >
                                                    Turned off
                                                </UserBadge>
                                            )}
                                            {user.password_change_required && (
                                                <UserBadge
                                                    tone="amber"
                                                    testId="auth-user-must-change"
                                                >
                                                    Must choose new password
                                                </UserBadge>
                                            )}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {user.email ?? '—'}
                                    </td>
                                    {capabilities.roles && (
                                        <td
                                            className="px-3 py-2 whitespace-nowrap capitalize"
                                            data-test="auth-user-role"
                                        >
                                            {user.role ?? '—'}
                                        </td>
                                    )}
                                    <td className="hidden px-3 py-2 whitespace-nowrap text-muted-foreground @3xl:table-cell">
                                        {formatWhen(user.created_at)}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                        {user.last_login_at === null
                                            ? 'Never'
                                            : formatWhen(user.last_login_at)}
                                    </td>
                                    <td className="px-2 py-1 whitespace-nowrap">
                                        {user.id !== null && (
                                            <UserActions
                                                user={user}
                                                capabilities={capabilities}
                                                roles={roles}
                                                onDialog={setDialog}
                                                onSignInAs={() =>
                                                    signInAs(user)
                                                }
                                                onAct={(values, done) =>
                                                    act(
                                                        authApi.updateUser(
                                                            projectId,
                                                            String(user.id),
                                                            values,
                                                        ),
                                                        done,
                                                    )
                                                }
                                                onSignOut={() =>
                                                    act(
                                                        authApi.signOutUser(
                                                            projectId,
                                                            String(user.id),
                                                        ),
                                                        `Signed ${user.name ?? user.email} out everywhere.`,
                                                    )
                                                }
                                            />
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {result?.users.length === 0 && (
                        <div
                            className="p-8 text-center text-sm text-muted-foreground"
                            data-test="auth-users-empty"
                        >
                            <Users className="mx-auto mb-2 size-5" />
                            {query ? (
                                'No users match that search.'
                            ) : (
                                <>
                                    <p className="font-medium text-foreground">
                                        No users yet
                                    </p>
                                    <p className="mt-1">
                                        People who sign up in your app appear
                                        here.
                                    </p>
                                    {status.login_url && (
                                        <a
                                            href={status.login_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-3 inline-flex items-center gap-1 underline underline-offset-4"
                                        >
                                            Open the sign-in page{' '}
                                            <ExternalLink className="size-3.5" />
                                        </a>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                </div>
            )}

            {pages > 1 && (
                <div className="flex items-center justify-end gap-2 text-sm">
                    <Button
                        size="icon"
                        variant="outline"
                        className="size-8"
                        aria-label="Previous page"
                        disabled={page <= 1}
                        onClick={() => setPage(page - 1)}
                    >
                        <ChevronLeft />
                    </Button>
                    <span className="text-muted-foreground">
                        Page {page} of {pages}
                    </span>
                    <Button
                        size="icon"
                        variant="outline"
                        className="size-8"
                        aria-label="Next page"
                        disabled={page >= pages}
                        onClick={() => setPage(page + 1)}
                    >
                        <ChevronRight />
                    </Button>
                </div>
            )}
        </div>
    );
}

function UserBadge({
    tone,
    testId,
    children,
}: {
    tone: 'muted' | 'amber';
    testId: string;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'rounded-md px-1.5 py-0.5 text-[11px] font-medium',
                tone === 'muted'
                    ? 'bg-muted text-muted-foreground'
                    : 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
            )}
            data-test={testId}
        >
            {children}
        </span>
    );
}

type UserDialogState =
    | { kind: 'create' }
    | { kind: 'edit'; user: AppUser }
    | { kind: 'password'; user: AppUser }
    | { kind: 'delete'; user: AppUser }
    | { kind: 'upgrade'; capabilities: AppUserCapabilities };

/**
 * A user's actions. Controls the app doesn't support yet stay listed, and open an offer
 * to have the agent add them.
 */
function UserActions({
    user,
    capabilities,
    roles,
    onDialog,
    onAct,
    onSignOut,
    onSignInAs,
}: {
    user: AppUser;
    capabilities: AppUserCapabilities;
    roles: string[];
    onDialog: (state: UserDialogState) => void;
    onAct: (
        values: {
            disabled?: boolean;
            password_change_required?: boolean;
            role?: string;
        },
        done: string,
    ) => void;
    onSignOut: () => void;
    onSignInAs: () => void;
}) {
    // Dialogs open once the menu has closed and let go of focus, so their input keeps it.
    const pending = useRef<UserDialogState | null>(null);
    const who = user.name ?? user.email ?? 'this user';
    const disabled = user.disabled_at !== null;
    const upgrade = { kind: 'upgrade', capabilities } as const;

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={`Actions for ${user.email ?? who}`}
                    data-test="auth-user-actions"
                >
                    <EllipsisVertical />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();

                    if (pending.current) {
                        onDialog(pending.current);
                        pending.current = null;
                    }
                }}
            >
                <DropdownMenuItem
                    onSelect={() => (pending.current = { kind: 'edit', user })}
                    data-test="auth-edit-user"
                >
                    <Pencil /> Edit name and email
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        (pending.current = capabilities.helper
                            ? { kind: 'password', user }
                            : upgrade)
                    }
                    data-test="auth-set-password"
                >
                    <KeyRound /> Set password…
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        capabilities.require_password_change
                            ? onAct(
                                  {
                                      password_change_required:
                                          !user.password_change_required,
                                  },
                                  user.password_change_required
                                      ? `${who} no longer has to choose a new password.`
                                      : `${who} will choose a new password next time they use the app.`,
                              )
                            : (pending.current = upgrade)
                    }
                    data-test="auth-require-password"
                >
                    <KeySquare />
                    {user.password_change_required
                        ? "Don't require a new password"
                        : 'Require a new password'}
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        capabilities.helper
                            ? onSignOut()
                            : (pending.current = upgrade)
                    }
                    data-test="auth-sign-out-user"
                >
                    <LogOut /> Sign out everywhere
                </DropdownMenuItem>
                {capabilities.roles ? (
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger data-test="auth-change-role">
                            <Shield /> Role
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            <DropdownMenuRadioGroup
                                value={user.role ?? ''}
                                onValueChange={(role) =>
                                    role !== user.role &&
                                    onAct({ role }, `${who} is now ${role}.`)
                                }
                            >
                                {roles.map((role) => (
                                    <DropdownMenuRadioItem
                                        key={role}
                                        value={role}
                                        className="capitalize"
                                        data-test={`auth-role-${role}`}
                                    >
                                        {role}
                                    </DropdownMenuRadioItem>
                                ))}
                            </DropdownMenuRadioGroup>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                ) : (
                    <DropdownMenuItem
                        onSelect={() => (pending.current = upgrade)}
                        data-test="auth-change-role"
                    >
                        <Shield /> Role…
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem
                    onSelect={() =>
                        capabilities.sign_in_as
                            ? onSignInAs()
                            : (pending.current = upgrade)
                    }
                    data-test="auth-sign-in-as"
                >
                    <Eye /> Sign in as {user.name ?? 'this user'}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() =>
                        capabilities.disable
                            ? onAct(
                                  { disabled: !disabled },
                                  disabled
                                      ? `${who} can sign in again.`
                                      : `${who} can't sign in any more.`,
                              )
                            : (pending.current = upgrade)
                    }
                    data-test="auth-toggle-disabled"
                >
                    {disabled ? <UserCheck /> : <UserX />}
                    {disabled ? 'Turn account on' : 'Turn account off'}
                </DropdownMenuItem>
                <DropdownMenuItem
                    variant="destructive"
                    onSelect={() =>
                        (pending.current = { kind: 'delete', user })
                    }
                    data-test="auth-delete-user"
                >
                    <Trash2 /> Delete user…
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function UserDialogs({
    projectId,
    state,
    onClose,
    onSaved,
}: {
    projectId: number;
    state: UserDialogState;
    onClose: () => void;
    onSaved: () => void;
}) {
    const content =
        state.kind === 'create' || state.kind === 'edit' ? (
            <DetailsForm
                projectId={projectId}
                user={state.kind === 'edit' ? state.user : null}
                onClose={onClose}
                onSaved={onSaved}
            />
        ) : state.kind === 'password' ? (
            <PasswordForm
                projectId={projectId}
                user={state.user}
                onClose={onClose}
                onSaved={onSaved}
            />
        ) : state.kind === 'delete' ? (
            <DeleteConfirm
                projectId={projectId}
                user={state.user}
                onClose={onClose}
                onDeleted={onSaved}
            />
        ) : (
            <UpgradeOffer
                projectId={projectId}
                capabilities={state.capabilities}
            />
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md" data-test="auth-user-dialog">
                {content}
            </DialogContent>
        </Dialog>
    );
}

/** Add a user (with a password, through the app's helper), or change a user's name and email. */
function DetailsForm({
    projectId,
    user,
    onClose,
    onSaved,
}: {
    projectId: number;
    user: AppUser | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [name, setName] = useState(user?.name ?? '');
    const [email, setEmail] = useState(user?.email ?? '');
    const [password, setPassword] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const unchanged =
        user !== null &&
        name === (user.name ?? '') &&
        email === (user.email ?? '');

    const save = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);

        (user
            ? authApi.updateUser(projectId, String(user.id), {
                  ...(name !== (user.name ?? '') ? { name } : {}),
                  ...(email !== (user.email ?? '') ? { email } : {}),
              })
            : authApi.createUser(projectId, { name, email, password })
        )
            .then(onSaved)
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    return (
        <form onSubmit={save} className="space-y-3">
            <DialogHeader>
                <DialogTitle>{user ? 'Edit user' : 'Add user'}</DialogTitle>
                <DialogDescription>
                    {user
                        ? 'Changes are saved straight to your app’s database.'
                        : 'They can sign in with this email and password right away.'}
                </DialogDescription>
            </DialogHeader>
            <div className="space-y-1">
                <Label htmlFor="auth-user-name">Name</Label>
                <Input
                    id="auth-user-name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    required
                    autoFocus
                    data-test="auth-user-name"
                />
            </div>
            <div className="space-y-1">
                <Label htmlFor="auth-user-email">Email</Label>
                <Input
                    id="auth-user-email"
                    type="email"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    required
                    data-test="auth-user-email"
                />
            </div>
            {!user && (
                <div className="space-y-1">
                    <Label htmlFor="auth-user-password">Password</Label>
                    <Input
                        id="auth-user-password"
                        type="password"
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                        required
                        minLength={8}
                        autoComplete="new-password"
                        placeholder="At least 8 characters"
                        data-test="auth-user-password"
                    />
                </div>
            )}
            {error && (
                <p className="text-sm text-red-600" data-test="auth-user-error">
                    {error}
                </p>
            )}
            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={saving || unchanged}
                    data-test="auth-user-save"
                >
                    {user ? 'Save' : 'Add user'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function PasswordForm({
    projectId,
    user,
    onClose,
    onSaved,
}: {
    projectId: number;
    user: AppUser;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [password, setPassword] = useState('');
    const [signOut, setSignOut] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const save = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        authApi
            .updateUser(projectId, String(user.id), {
                password,
                sign_out: signOut,
            })
            .then(() => {
                toast.success(
                    `Password changed for ${user.name ?? user.email}.`,
                );
                onSaved();
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    return (
        <form onSubmit={save} className="space-y-3">
            <DialogHeader>
                <DialogTitle>Set password</DialogTitle>
                <DialogDescription>
                    A new password for {user.name ?? user.email}. Tell them what
                    it is; they can change it later.
                </DialogDescription>
            </DialogHeader>
            <div className="space-y-1">
                <Label htmlFor="auth-user-new-password">New password</Label>
                <Input
                    id="auth-user-new-password"
                    type="password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    required
                    minLength={8}
                    autoFocus
                    autoComplete="new-password"
                    placeholder="At least 8 characters"
                    data-test="auth-user-new-password"
                />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={signOut}
                    onCheckedChange={(checked) => setSignOut(checked === true)}
                    data-test="auth-password-sign-out"
                />
                Sign them out everywhere
            </label>
            {error && (
                <p className="text-sm text-red-600" data-test="auth-user-error">
                    {error}
                </p>
            )}
            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={saving}
                    data-test="auth-password-save"
                >
                    Set password
                </Button>
            </DialogFooter>
        </form>
    );
}

function DeleteConfirm({
    projectId,
    user,
    onClose,
    onDeleted,
}: {
    projectId: number;
    user: AppUser;
    onClose: () => void;
    onDeleted: () => void;
}) {
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const remove = () => {
        setDeleting(true);
        authApi
            .deleteUser(projectId, String(user.id))
            .then(onDeleted)
            .catch((e: Error) => setError(e.message))
            .finally(() => setDeleting(false));
    };

    return (
        <div className="space-y-3" data-test="auth-delete-dialog">
            <DialogHeader>
                <DialogTitle>Delete this user?</DialogTitle>
                <DialogDescription>
                    {user.name ?? user.email} won’t be able to sign in any more.
                    This removes their account from your app’s database and
                    can’t be undone. To keep it, turn the account off instead.
                </DialogDescription>
            </DialogHeader>
            {error && <p className="text-sm text-red-600">{error}</p>}
            <DialogFooter>
                <Button variant="ghost" onClick={onClose} autoFocus>
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    onClick={remove}
                    disabled={deleting}
                    data-test="auth-delete-confirm"
                >
                    <Trash2 /> Delete user
                </Button>
            </DialogFooter>
        </div>
    );
}

/** For apps set up before some account controls existed: have the agent add what's missing. */
function UpgradeOffer({
    projectId,
    capabilities,
}: {
    projectId: number;
    capabilities: AppUserCapabilities;
}) {
    const [asked, setAsked] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const missing = [
        !capabilities.helper && 'add users, set passwords and sign people out',
        !capabilities.disable && 'turn accounts off',
        !capabilities.require_password_change && 'require a new password',
        !capabilities.roles && 'change roles',
        !capabilities.sign_in_as && 'sign in as a user',
    ].filter(Boolean);

    const ask = () =>
        authApi
            .requestHelper(projectId)
            .then(() => {
                setAsked(true);
                router.reload();
            })
            .catch((e: Error) => setError(e.message));

    return (
        <div className="space-y-3 text-sm" data-test="auth-needs-helper">
            <DialogHeader>
                <DialogTitle>Your app needs a small update</DialogTitle>
                <DialogDescription>
                    Your app’s sign-in was set up before these controls existed.
                    The agent can add the rest so you can {missing.join(', ')}{' '}
                    from here.
                </DialogDescription>
            </DialogHeader>
            {asked ? (
                <p>Asked the agent. Follow along in the chat.</p>
            ) : (
                <Button onClick={ask} autoFocus data-test="auth-request-helper">
                    Ask the agent <ArrowRight />
                </Button>
            )}
            {error && <p className="text-red-600">{error}</p>}
        </div>
    );
}

function Configure({
    projectId,
    status,
    working,
    onChange,
}: {
    projectId: number;
    status: Configured;
    working: boolean;
    onChange: (status: AppAuthStatus) => void;
}) {
    const [draft, setDraft] = useState<AppAuthMethod[]>(status.methods);
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const current = status.methods.join();

    // The app's methods changed (the agent finished): start over from them.
    useEffect(() => {
        setDraft(current ? (current.split(',') as AppAuthMethod[]) : []);
        setSent(false);
    }, [current]);

    const changed =
        draft.length !== status.methods.length ||
        draft.some((m) => !status.methods.includes(m));

    const askAgent = () => {
        setSending(true);
        authApi
            .setup(projectId, draft)
            .then(() => {
                setSent(true);
                setError(null);
                router.reload();
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setSending(false));
    };

    return (
        <div className="space-y-8">
            <section className="space-y-2">
                <dl className="grid grid-cols-[9rem_1fr] gap-y-2 rounded-xl border border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border">
                    {status.library && (
                        <>
                            <dt className="text-muted-foreground">
                                Built with
                            </dt>
                            <dd>{status.library}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Users stored in</dt>
                    <dd>
                        The <code>{status.users_table}</code> table of your
                        app's database
                    </dd>
                    <dt className="text-muted-foreground">Sign-in page</dt>
                    <dd className="flex flex-wrap gap-x-4 gap-y-1">
                        {status.login_url && (
                            <a
                                href={status.login_url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 underline underline-offset-4"
                                data-test="auth-login-link"
                            >
                                Preview <ExternalLink className="size-3.5" />
                            </a>
                        )}
                        {status.published_login_url && (
                            <a
                                href={status.published_login_url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 underline underline-offset-4"
                            >
                                Published <ExternalLink className="size-3.5" />
                            </a>
                        )}
                    </dd>
                </dl>
            </section>

            <section>
                <h3 className="text-sm font-medium">Sign-in methods</h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    Turning a method on or off asks the agent to change your
                    app. Existing users are kept.
                </p>
                <ul className="mt-3 space-y-2">
                    {METHODS.map(({ id, label, icon: Icon }) => {
                        const provider = status.providers.find(
                            (p) => p.id === id,
                        );
                        const on = draft.includes(id);

                        return (
                            <li
                                key={id}
                                className="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                                data-test={`auth-config-${id}`}
                            >
                                <div className="flex items-center gap-3 px-4 py-3">
                                    <Icon className="size-5 shrink-0" />
                                    <span className="text-sm font-medium">
                                        {label}
                                    </span>
                                    <MethodBadge
                                        enabled={status.methods.includes(id)}
                                        provider={provider}
                                    />
                                    <Switch
                                        className="ml-auto"
                                        checked={on}
                                        label={`Offer ${label} sign-in`}
                                        onChange={(next) =>
                                            setDraft((current) =>
                                                next
                                                    ? [...current, id]
                                                    : current.filter(
                                                          (m) => m !== id,
                                                      ),
                                            )
                                        }
                                        testId={`auth-toggle-${id}`}
                                    />
                                </div>
                                {id === 'onedrop' &&
                                    status.methods.includes('onedrop') && (
                                        <OneDropAccess
                                            projectId={projectId}
                                            oneDrop={status.onedrop}
                                            onSaved={(onedrop) =>
                                                onChange({ ...status, onedrop })
                                            }
                                        />
                                    )}
                                {provider?.enabled && (
                                    <ProviderKeys
                                        projectId={projectId}
                                        provider={provider}
                                        label={label}
                                        envFile={status.env_file}
                                        onSaved={onChange}
                                    />
                                )}
                            </li>
                        );
                    })}
                </ul>

                {(changed || sent) && (
                    <div
                        className="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-muted p-3 text-sm"
                        data-test="auth-config-pending"
                    >
                        {sent ? (
                            <span>
                                {working
                                    ? 'The agent is updating sign-in. Follow along in the chat.'
                                    : 'Asked the agent to update sign-in.'}
                            </span>
                        ) : (
                            <>
                                <span className="flex-1">
                                    {draft.length === 0
                                        ? 'Keep at least one way to sign in.'
                                        : 'Ask the agent to update your app?'}
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setDraft(status.methods)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={askAgent}
                                    disabled={draft.length === 0 || sending}
                                    data-test="auth-config-send"
                                >
                                    Update with agent <ArrowRight />
                                </Button>
                            </>
                        )}
                        {error && (
                            <p className="w-full text-red-600">{error}</p>
                        )}
                    </div>
                )}
            </section>
        </div>
    );
}

/** Who may sign in with their OneDrop account: everyone, or members of chosen groups. */
function OneDropAccess({
    projectId,
    oneDrop,
    onSaved,
}: {
    projectId: number;
    oneDrop: AppOneDrop;
    onSaved: (oneDrop: AppOneDrop) => void;
}) {
    const [error, setError] = useState<string | null>(null);
    const everyone = oneDrop.group_ids === null;

    const save = (groupIds: number[] | null) =>
        authApi
            .setOneDropAccess(projectId, groupIds)
            .then((next) => {
                setError(null);
                onSaved(next);
            })
            .catch((e: Error) => setError(e.message));

    return (
        <div
            className="space-y-3 border-t border-sidebar-border/70 px-4 py-4 text-sm dark:border-sidebar-border"
            data-test="auth-onedrop-access"
        >
            <p className="text-muted-foreground">
                People sign in with their OneDrop login. Keys are set up for
                you.
                {!oneDrop.enabled &&
                    ' Sign-ins through OneDrop are off for this app right now.'}
            </p>
            <fieldset className="space-y-2">
                <legend className="mb-1 font-medium">Who can sign in</legend>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        name="onedrop-access"
                        checked={everyone}
                        onChange={() => void save(null)}
                        data-test="auth-onedrop-everyone"
                    />
                    Everyone with a OneDrop account
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        name="onedrop-access"
                        checked={!everyone}
                        onChange={() => void save([])}
                        disabled={oneDrop.groups.length === 0}
                        data-test="auth-onedrop-groups"
                    />
                    Only members of these groups
                    {oneDrop.groups.length === 0 && (
                        <span className="text-muted-foreground">
                            (no groups yet)
                        </span>
                    )}
                </label>
                {!everyone && (
                    <ul className="ml-6 space-y-1">
                        {oneDrop.groups.map((group) => {
                            const ids = oneDrop.group_ids ?? [];
                            const checked = ids.includes(group.id);

                            return (
                                <li key={group.id}>
                                    <label className="flex items-center gap-2">
                                        <Checkbox
                                            checked={checked}
                                            onCheckedChange={() =>
                                                void save(
                                                    checked
                                                        ? ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  group.id,
                                                          )
                                                        : [...ids, group.id],
                                                )
                                            }
                                            data-test={`auth-onedrop-group-${group.id}`}
                                        />
                                        {group.name}
                                    </label>
                                </li>
                            );
                        })}
                        {oneDrop.group_ids?.length === 0 && (
                            <li className="text-amber-700 dark:text-amber-400">
                                Choose at least one group; until then only you
                                can sign in this way.
                            </li>
                        )}
                    </ul>
                )}
            </fieldset>
            {error && <p className="text-red-600">{error}</p>}
        </div>
    );
}

function MethodBadge({
    enabled,
    provider,
}: {
    enabled: boolean;
    provider?: AppAuthProvider;
}) {
    const [text, tone] = !enabled
        ? ['Off', 'bg-muted text-muted-foreground']
        : provider && !(provider.client_id_set && provider.client_secret_set)
          ? ['Needs keys', 'bg-amber-500/15 text-amber-700 dark:text-amber-400']
          : ['On', 'bg-green-500/15 text-green-700 dark:text-green-400'];

    return (
        <span
            className={cn('rounded-md px-2 py-0.5 text-xs font-medium', tone)}
            data-test="auth-method-status"
        >
            {text}
        </span>
    );
}

/** A provider's callback URLs, and fields to save its keys into the app's env file. */
function ProviderKeys({
    projectId,
    provider,
    label,
    envFile,
    onSaved,
}: {
    projectId: number;
    provider: AppAuthProvider;
    label: string;
    envFile: string;
    onSaved: (status: AppAuthStatus) => void;
}) {
    const [clientId, setClientId] = useState('');
    const [clientSecret, setClientSecret] = useState('');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, copy] = useClipboard();
    const prefix = provider.id.toUpperCase();

    const save = () => {
        setSaving(true);
        authApi
            .saveKeys(projectId, provider.id, {
                ...(clientId ? { client_id: clientId.trim() } : {}),
                ...(clientSecret ? { client_secret: clientSecret.trim() } : {}),
            })
            .then((next) => {
                setClientId('');
                setClientSecret('');
                setSaved(true);
                setError(null);
                onSaved(next);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    return (
        <div
            className="space-y-4 border-t border-sidebar-border/70 px-4 py-4 text-sm dark:border-sidebar-border"
            data-test={`auth-keys-${provider.id}`}
        >
            <div>
                <p className="font-medium">1. Create an OAuth app</p>
                <p className="mt-1 text-muted-foreground">
                    In{' '}
                    <a
                        href={CONSOLES[provider.id]}
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-4"
                    >
                        {label}'s developer console
                    </a>
                    , create an OAuth app and add{' '}
                    {provider.callback_urls.length === 1
                        ? 'this callback URL'
                        : 'these callback URLs'}
                    :
                </p>
                <ul className="mt-2 space-y-1">
                    {provider.callback_urls.map(({ label: where, url }) => (
                        <li key={url} className="flex items-center gap-2">
                            <span className="w-20 shrink-0 text-xs text-muted-foreground">
                                {where}
                            </span>
                            <code className="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 text-xs">
                                {url}
                            </code>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                aria-label={`Copy ${where} callback URL`}
                                onClick={() => void copy(url)}
                            >
                                {copied === url ? <Check /> : <Copy />}
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>

            <div>
                <p className="font-medium">2. Paste its keys</p>
                <p className="mt-1 text-muted-foreground">
                    Saved as <code>{prefix}_CLIENT_ID</code> and{' '}
                    <code>{prefix}_CLIENT_SECRET</code> in your app's{' '}
                    <code>{envFile}</code>. The app builder doesn't keep them.
                </p>
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    <Input
                        value={clientId}
                        onChange={(event) => setClientId(event.target.value)}
                        placeholder={
                            provider.client_id_set
                                ? 'Client ID saved · paste to replace'
                                : 'Client ID'
                        }
                        aria-label={`${label} client ID`}
                        autoComplete="off"
                        data-test={`auth-client-id-${provider.id}`}
                    />
                    <Input
                        type="password"
                        value={clientSecret}
                        onChange={(event) =>
                            setClientSecret(event.target.value)
                        }
                        placeholder={
                            provider.client_secret_set
                                ? 'Secret saved · paste to replace'
                                : 'Client secret'
                        }
                        aria-label={`${label} client secret`}
                        autoComplete="new-password"
                        data-test={`auth-client-secret-${provider.id}`}
                    />
                </div>
                <div className="mt-2 flex items-center gap-3">
                    <Button
                        size="sm"
                        onClick={save}
                        disabled={saving || (!clientId && !clientSecret)}
                        data-test={`auth-save-keys-${provider.id}`}
                    >
                        <KeyRound /> Save keys
                    </Button>
                    {saved && !error && (
                        <span
                            className="text-muted-foreground"
                            data-test={`auth-keys-saved-${provider.id}`}
                        >
                            Saved. Your app is restarting to use them.
                        </span>
                    )}
                    {error && <span className="text-red-600">{error}</span>}
                </div>
            </div>
        </div>
    );
}

/** A date from the app's database (ISO text, "Y-m-d H:i:s", or a Unix timestamp), in the viewer's locale. */
function formatWhen(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }

    const date =
        typeof value === 'number' || /^\d+$/.test(String(value))
            ? new Date(Number(value) * (Number(value) > 1e12 ? 1 : 1000))
            : new Date(isoDate(String(value)));

    return Number.isNaN(date.getTime())
        ? String(value)
        : date.toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}

/**
 * "2026-09-28 03:40:43.993+00" (Postgres) → "2026-09-28T03:40:43.993+00:00". Values without a zone
 * are taken as UTC, which is how frameworks store them.
 */
function isoDate(value: string): string {
    const iso = value
        .trim()
        .replace(' ', 'T')
        .replace(/([+-]\d{2})$/, '$1:00');

    return /T[\d:.]+$/.test(iso) ? `${iso}Z` : iso;
}

function Empty({ children, tone }: { children: ReactNode; tone?: 'error' }) {
    return (
        <div
            className={cn(
                'max-w-xl rounded-lg border border-dashed p-6 text-center text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
            )}
            data-test="auth-empty"
        >
            {children}
        </div>
    );
}

function GoogleMark({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
            <path
                fill="#4285F4"
                d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.4h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.2-2.1 3.5-5.1 3.5-8.7Z"
            />
            <path
                fill="#34A853"
                d="M12 24c3.2 0 6-1.1 7.9-2.9l-3.9-3c-1 .7-2.4 1.1-4 1.1-3.1 0-5.7-2.1-6.6-4.9h-4v3.1A12 12 0 0 0 12 24Z"
            />
            <path
                fill="#FBBC05"
                d="M5.4 14.3a7.2 7.2 0 0 1 0-4.6V6.6h-4a12 12 0 0 0 0 10.8l4-3.1Z"
            />
            <path
                fill="#EA4335"
                d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.4 6.6l4 3.1c.9-2.8 3.5-4.9 6.6-4.9Z"
            />
        </svg>
    );
}

function GitHubMark({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={className}
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M12 .3a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2c-3.3.7-4-1.6-4-1.6-.6-1.4-1.4-1.8-1.4-1.8-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.8 1.3 3.5 1 0-.8.4-1.3.7-1.6-2.7-.3-5.5-1.3-5.5-6 0-1.2.5-2.3 1.3-3.1-.2-.4-.6-1.6 0-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.3 4.7 18.3 5 18.3 5c.6 1.6.2 2.8.1 3.2.8.8 1.3 1.9 1.3 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .3" />
        </svg>
    );
}

function OneDropMark({ className }: { className?: string }) {
    return <AppLogoIcon className={className} aria-hidden="true" />;
}

function MicrosoftMark({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
            <path fill="#F25022" d="M1 1h10.5v10.5H1z" />
            <path fill="#7FBA00" d="M12.5 1H23v10.5H12.5z" />
            <path fill="#00A4EF" d="M1 12.5h10.5V23H1z" />
            <path fill="#FFB900" d="M12.5 12.5H23V23H12.5z" />
        </svg>
    );
}
