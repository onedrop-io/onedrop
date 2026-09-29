import {
    ArrowLeft,
    BookOpen,
    Check,
    ChevronRight,
    Copy,
    CornerDownRight,
    ExternalLink,
    KeyRound,
    Monitor,
    Network,
    Plus,
    RefreshCw,
    SquareTerminal,
    Trash2,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ProjectDeveloperController from '@/actions/App/Http/Controllers/ProjectDeveloperController';
import SshKeyController from '@/actions/App/Http/Controllers/SshKeyController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { isLocalHostname, useIsRemote } from '@/hooks/use-is-remote';
import { jsonRequest } from '@/lib/json-request';
import { formatBytes } from '@/lib/storage-api';
import { cn } from '@/lib/utils';

type Page = 'home' | 'networking' | 'resources' | 'ssh-connect' | 'ssh-keys';

type Port = {
    address: string;
    port: number;
    pid: number | null;
    process: string | null;
    role: 'app' | 'proxy' | 'shell' | 'ssh' | null;
};

type Networking = {
    preview: { url: string; local: boolean; qr: string | null } | null;
    published: {
        url: string;
        visibility: 'private' | 'public' | null;
        qr: string;
    } | null;
    app_port: number;
    ports: Port[];
};

type Usage = {
    cpus: number | null;
    cpu: number | null;
    user: number | null;
    system: number | null;
    memory_limit: number | null;
    memory: number | null;
    active: number | null;
    cache: number | null;
};

type Storage = {
    workspace: number | null;
    dependencies: number | null;
    storage: number | null;
    disk_total: number | null;
    disk_free: number | null;
};

type Ssh = {
    unavailable: string | null;
    host_alias: string;
    host: string | null;
    port: number | null;
    user: string;
    path: string;
    owner_keys: number;
    is_owner: boolean;
    owner_name: string;
};

type SshKey = {
    id: number;
    name: string;
    type: string;
    fingerprint: string;
    created_at: string | null;
};

const PAGES: {
    id: Exclude<Page, 'home'>;
    label: string;
    icon: LucideIcon;
    description: string;
    group?: string;
}[] = [
    {
        id: 'networking',
        label: 'Networking',
        icon: Network,
        description: "Your app's addresses and the ports open in its sandbox",
    },
    {
        id: 'resources',
        label: 'Resources',
        icon: Monitor,
        description: 'CPU, memory and storage your app is using',
    },
    {
        id: 'ssh-connect',
        label: 'Connect',
        icon: Zap,
        description: 'Open your app in VS Code, Cursor or a terminal over SSH',
        group: 'SSH',
    },
    {
        id: 'ssh-keys',
        label: 'Keys',
        icon: KeyRound,
        description: 'Manage the SSH public keys you sign in with',
        group: 'SSH',
    },
];

/** How often Resources refreshes CPU and memory, and how many samples its trend lines keep. */
const USAGE_INTERVAL = 3000;
const USAGE_SAMPLES = 40;

/**
 * Developer tools: networking, resources and SSH access for the project's sandbox.
 */
export default function DeveloperPanel({
    projectId,
    running,
}: {
    projectId: number;
    running: boolean;
}) {
    const [page, setPage] = useState<Page>('home');
    const current = PAGES.find((p) => p.id === page);

    if (!current) {
        return (
            <div className="max-w-3xl" data-test="developer-panel">
                {PAGES.map((item, index) => (
                    <div key={item.id}>
                        {item.group &&
                            PAGES[index - 1]?.group !== item.group && (
                                <h3 className="mt-4 mb-1 px-3 text-sm font-medium">
                                    {item.group}
                                </h3>
                            )}
                        <button
                            type="button"
                            onClick={() => setPage(item.id)}
                            className="flex w-full items-center gap-4 rounded-lg px-3 py-3 text-left hover:bg-muted"
                            data-test={`developer-${item.id}`}
                        >
                            <item.icon className="size-5 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium">
                                    {item.label}
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    {item.description}
                                </span>
                            </span>
                            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                        </button>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="max-w-4xl" data-test={`developer-page-${page}`}>
            <div className="mb-5 flex items-center gap-3 text-sm">
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    onClick={() => setPage('home')}
                    aria-label="Back to developer tools"
                    data-test="developer-back"
                >
                    <ArrowLeft />
                </Button>
                {current.group && (
                    <span className="flex items-center gap-1.5 text-muted-foreground">
                        <SquareTerminal className="size-4" />
                        {current.group}
                        <ChevronRight className="size-3.5" />
                    </span>
                )}
                <span className="flex items-center gap-1.5 font-medium">
                    <current.icon className="size-4 text-muted-foreground" />
                    {current.label}
                </span>
            </div>

            {!running ? (
                <Empty>
                    {current.label} details appear when the sandbox is running.
                </Empty>
            ) : page === 'networking' ? (
                <NetworkingPage projectId={projectId} />
            ) : page === 'resources' ? (
                <ResourcesPage projectId={projectId} />
            ) : page === 'ssh-connect' ? (
                <SshConnectPage
                    projectId={projectId}
                    onManageKeys={() => setPage('ssh-keys')}
                />
            ) : (
                <SshKeysPage projectId={projectId} />
            )}
        </div>
    );
}

/** Load JSON from a URL, with a reload function. */
function useJson<T>(url: string) {
    const [data, setData] = useState<T | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);

    const reload = useCallback(() => {
        setLoading(true);

        return jsonRequest<T>(url)
            .then((loaded) => {
                setData(loaded);
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [url]);

    useEffect(() => {
        void reload();
    }, [reload]);

    return { data, error, loading, reload };
}

const ROLES: Record<NonNullable<Port['role']>, string> = {
    app: 'Your app',
    proxy: 'Preview and published address',
    shell: 'Shell tab',
    ssh: 'SSH',
};

function NetworkingPage({ projectId }: { projectId: number }) {
    const { data, error, loading, reload } = useJson<Networking>(
        ProjectDeveloperController.networking.url(projectId),
    );

    if (error && !data) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!data) {
        return <Empty>Loading…</Empty>;
    }

    return (
        <div className="space-y-8">
            <section className="space-y-4">
                <h3 className="font-medium">Addresses</h3>
                {data.preview ? (
                    <Address
                        label="Preview"
                        url={data.preview.url}
                        qr={data.preview.qr}
                        testId="developer-preview-url"
                        note={
                            data.preview.local
                                ? 'Works in a browser on the machine running the app builder. Publish to open it on other devices.'
                                : 'Only people who can open this project can see the preview.'
                        }
                    />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        The preview appears once the sandbox has one.
                    </p>
                )}
                {data.published ? (
                    <Address
                        label="Published"
                        url={data.published.url}
                        qr={data.published.qr}
                        testId="developer-published-url"
                        note={
                            data.published.visibility === 'public'
                                ? 'Anyone with the URL can open it.'
                                : "People on your team's tailnet can open it."
                        }
                    />
                ) : (
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="developer-not-published"
                    >
                        Not published yet. Use Publish at the top right to give
                        your app its own address.
                    </p>
                )}
            </section>

            <section className="space-y-3">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h3 className="font-medium">Ports</h3>
                        <p className="text-sm text-muted-foreground">
                            Programs listening inside the sandbox. The preview
                            shows your app on port{' '}
                            <code className="font-mono">{data.app_port}</code> (
                            <code className="font-mono">$PORT</code>); ask the
                            agent if your app should use it.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => void reload()}
                        disabled={loading}
                        data-test="developer-ports-refresh"
                    >
                        <RefreshCw className={cn(loading && 'animate-spin')} />
                        Refresh
                    </Button>
                </div>
                {error && <p className="text-sm text-red-600">{error}</p>}
                <div className="overflow-x-auto rounded-lg border">
                    <table
                        className="w-full text-sm"
                        data-test="developer-ports"
                    >
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Address
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Process
                                </th>
                                <th className="px-3 py-2 font-medium">PID</th>
                                <th className="px-3 py-2 font-medium">
                                    Used for
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.ports.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-3 py-4 text-center text-muted-foreground"
                                    >
                                        Nothing is listening yet.
                                    </td>
                                </tr>
                            )}
                            {data.ports.map((port) => (
                                <tr
                                    key={`${port.address}:${port.port}`}
                                    className="border-t"
                                    data-test="developer-port"
                                >
                                    <td className="px-3 py-2 font-mono text-green-700 dark:text-green-500">
                                        {port.address.includes(':')
                                            ? `[${port.address}]`
                                            : port.address}
                                        :{port.port}
                                    </td>
                                    <td className="px-3 py-2">
                                        {port.process ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {port.pid ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {port.role
                                            ? ROLES[port.role]
                                            : 'Inside the sandbox only'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}

function Address({
    label,
    url,
    qr,
    note,
    testId,
}: {
    label: string;
    url: string;
    qr: string | null;
    note: string;
    testId: string;
}) {
    return (
        <div
            className="flex items-start gap-4 rounded-lg border p-4"
            data-test={testId}
        >
            <div className="min-w-0 flex-1 space-y-1">
                <p className="text-sm font-medium">{label}</p>
                <div className="flex items-center gap-1">
                    <a
                        href={url}
                        target="_blank"
                        rel="noreferrer"
                        className="truncate font-mono text-sm text-green-700 underline-offset-4 hover:underline dark:text-green-500"
                    >
                        {url}
                    </a>
                    <CopyButton
                        text={url}
                        label={`Copy ${label.toLowerCase()} URL`}
                    />
                </div>
                <p className="text-sm text-muted-foreground">{note}</p>
            </div>
            {qr && (
                <div
                    className="size-28 shrink-0 overflow-hidden rounded-md bg-white p-1 [&>svg]:size-full"
                    role="img"
                    aria-label={`QR code for the ${label.toLowerCase()} URL`}
                    data-test="developer-qr"
                    dangerouslySetInnerHTML={{ __html: qr }}
                />
            )}
        </div>
    );
}

function ResourcesPage({ projectId }: { projectId: number }) {
    const [usage, setUsage] = useState<Usage | null>(null);
    const [history, setHistory] = useState<Usage[]>([]);
    const [usageError, setUsageError] = useState<string | null>(null);
    const storage = useJson<{ storage: Storage }>(
        ProjectDeveloperController.storage.url(projectId),
    );

    useEffect(() => {
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout>;

        const sample = () =>
            jsonRequest<{ usage: Usage }>(
                ProjectDeveloperController.usage.url(projectId),
            )
                .then(({ usage }) => {
                    if (!cancelled) {
                        setUsage(usage);
                        setHistory((h) => [...h, usage].slice(-USAGE_SAMPLES));
                        setUsageError(null);
                    }
                })
                .catch((e: Error) => !cancelled && setUsageError(e.message))
                .finally(() => {
                    if (!cancelled) {
                        timer = setTimeout(sample, USAGE_INTERVAL);
                    }
                });

        void sample();

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [projectId]);

    const percent = (value: number | null) =>
        value === null
            ? '—'
            : `${+(value * 100).toFixed(value < 0.01 ? 2 : 1)}%`;
    const bytes = (value: number | null) =>
        value === null ? '—' : formatBytes(value);
    const ofMemory = (value: number | null) =>
        value === null || !usage?.memory_limit
            ? null
            : value / usage.memory_limit;
    const series = (pick: (u: Usage) => number | null) =>
        history.map(pick).map((v) => v ?? 0);

    const s = storage.data?.storage;
    const diskUsed =
        s?.disk_total && s.disk_free !== null
            ? s.disk_total - s.disk_free
            : null;
    const share = (value: number | null) =>
        value === null || !s?.disk_total
            ? null
            : `(${+((value / s.disk_total) * 100).toFixed(value / s.disk_total < 0.01 ? 1 : 0)}%)`;

    return (
        <div className="space-y-6">
            <Card
                title="Compute"
                aside={
                    usage && (
                        <span data-test="developer-limits">
                            {usage.cpus !== null &&
                                `${+usage.cpus.toFixed(2)} vCPU`}
                            {usage.cpus !== null &&
                                usage.memory_limit !== null &&
                                ', '}
                            {usage.memory_limit !== null &&
                                `${gibibytes(usage.memory_limit)} RAM`}
                        </span>
                    )
                }
            >
                {usageError && !usage ? (
                    <p className="px-4 py-3 text-sm text-red-600">
                        {usageError}
                    </p>
                ) : !usage ? (
                    <p className="px-4 py-3 text-sm text-muted-foreground">
                        Measuring…
                    </p>
                ) : (
                    <>
                        <Row
                            label="CPU"
                            hint="Share of the sandbox's CPU limit in use."
                            value={percent(usage.cpu)}
                            trend={series((u) => u.cpu)}
                            testId="developer-cpu"
                        />
                        <Row
                            label="User"
                            value={percent(usage.user)}
                            trend={series((u) => u.user)}
                            sub
                        />
                        <Row
                            label="System"
                            value={percent(usage.system)}
                            trend={series((u) => u.system)}
                            sub
                        />
                        <Row
                            label="Memory"
                            hint="Share of the sandbox's memory limit in use."
                            value={percent(ofMemory(usage.memory))}
                            trend={series((u) => ofMemory(u.memory))}
                            testId="developer-memory"
                        />
                        <Row
                            label="Active"
                            value={bytes(usage.active)}
                            trend={series((u) => ofMemory(u.active))}
                            sub
                        />
                        <Row
                            label="Cache"
                            value={bytes(usage.cache)}
                            trend={series((u) => ofMemory(u.cache))}
                            sub
                        />
                    </>
                )}
            </Card>

            <Card
                title="Storage"
                aside={
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label="Refresh storage"
                        onClick={() => void storage.reload()}
                        disabled={storage.loading}
                    >
                        <RefreshCw
                            className={cn(storage.loading && 'animate-spin')}
                        />
                    </Button>
                }
            >
                {storage.error && !s ? (
                    <p className="px-4 py-3 text-sm text-red-600">
                        {storage.error}
                    </p>
                ) : !s ? (
                    <p className="px-4 py-3 text-sm text-muted-foreground">
                        Measuring…
                    </p>
                ) : (
                    <>
                        <Row
                            label="This app"
                            value={`${bytes(s.workspace)} ${share(s.workspace) ?? ''}`}
                            testId="developer-storage-workspace"
                        />
                        <Row
                            label="Code"
                            value={bytes(
                                s.workspace !== null && s.dependencies !== null
                                    ? Math.max(0, s.workspace - s.dependencies)
                                    : null,
                            )}
                            sub
                        />
                        <Row
                            label="Dependencies"
                            hint="node_modules and vendor folders."
                            value={bytes(s.dependencies)}
                            sub
                        />
                        <Row
                            label="App Storage"
                            hint="Files your app keeps in its buckets."
                            value={bytes(s.storage)}
                        />
                        <Row
                            label="Disk"
                            hint="The disk the sandbox's files live on."
                            value={
                                diskUsed !== null
                                    ? `${bytes(diskUsed)} of ${bytes(s.disk_total)} ${share(diskUsed)}`
                                    : '—'
                            }
                        />
                    </>
                )}
            </Card>
        </div>
    );
}

/** Memory limits the way they're configured: "2 GiB", "512 MiB". */
function gibibytes(value: number): string {
    return value >= 2 ** 30
        ? `${+(value / 2 ** 30).toFixed(1)} GiB`
        : `${Math.round(value / 2 ** 20)} MiB`;
}

function Card({
    title,
    aside,
    children,
}: {
    title: string;
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="overflow-hidden rounded-lg border">
            <div className="flex items-center justify-between border-b bg-muted/50 px-4 py-2 text-sm">
                <h3 className="font-medium">{title}</h3>
                <div className="text-muted-foreground">{aside}</div>
            </div>
            <div className="py-1">{children}</div>
        </section>
    );
}

function Row({
    label,
    value,
    hint,
    trend,
    sub,
    testId,
}: {
    label: string;
    value: string;
    hint?: string;
    trend?: number[];
    sub?: boolean;
    testId?: string;
}) {
    return (
        <div
            className="flex items-center gap-4 px-4 py-1.5 text-sm"
            data-test={testId}
        >
            <div className={cn('min-w-0 flex-1', sub && 'pl-2')}>
                <span className="flex items-center gap-2">
                    {sub && (
                        <CornerDownRight className="size-3.5 text-muted-foreground" />
                    )}
                    <span className={cn(!sub && 'font-medium')}>{label}</span>
                </span>
                {hint && (
                    <p
                        className={cn(
                            'text-xs text-muted-foreground',
                            sub && 'pl-5.5',
                        )}
                    >
                        {hint}
                    </p>
                )}
            </div>
            <span className="tabular-nums">{value}</span>
            {trend && <Sparkline values={trend} />}
        </div>
    );
}

/** A tiny trend line of values between 0 and 1. */
function Sparkline({ values }: { values: number[] }) {
    const width = 64;
    const height = 18;
    const points = values.map((value, index) => {
        // Newest sample at the right edge; the line grows leftwards as samples arrive.
        const x =
            width - (values.length - 1 - index) * (width / (USAGE_SAMPLES - 1));
        const y = height - 1 - Math.min(1, Math.max(0, value)) * (height - 2);

        return `${x.toFixed(1)},${y.toFixed(1)}`;
    });

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className="shrink-0 text-blue-500"
            aria-hidden
        >
            <line
                x1={0}
                x2={width}
                y1={height - 0.5}
                y2={height - 0.5}
                className="stroke-border"
            />
            {points.length > 1 && (
                <polyline
                    points={points.join(' ')}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={1.5}
                    strokeLinejoin="round"
                />
            )}
        </svg>
    );
}

function SshConnectPage({
    projectId,
    onManageKeys,
}: {
    projectId: number;
    onManageKeys: () => void;
}) {
    const { data, error } = useJson<Ssh>(
        ProjectDeveloperController.ssh.url(projectId),
    );
    const isRemoteBrowser = useIsRemote();

    if (error && !data) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!data) {
        return <Empty>Loading…</Empty>;
    }

    const unreachable =
        !data.unavailable &&
        isRemoteBrowser &&
        data.host !== null &&
        isLocalHostname(data.host);
    const alias = data.host_alias;
    const config = [
        `Host ${alias}`,
        `    HostName ${data.host}`,
        `    Port ${data.port}`,
        `    User ${data.user}`,
        '    StrictHostKeyChecking no',
        '    UserKnownHostsFile /dev/null',
        '    LogLevel ERROR',
    ].join('\n');
    const remote = `ssh-remote+${alias}${data.path}`;

    return (
        <div className="space-y-6">
            <div>
                <h3 className="font-medium">
                    Use your app with another editor
                </h3>
                <p className="text-sm text-muted-foreground">
                    SSH lets you work on your app from VS Code, Cursor or a
                    terminal. Changes land in the sandbox, like the
                    agent&apos;s.
                </p>
            </div>

            {data.unavailable || unreachable ? (
                <Empty>
                    <span data-test="ssh-unavailable">
                        {data.unavailable ??
                            'SSH only works on the machine running this app builder.'}
                    </span>
                </Empty>
            ) : data.owner_keys === 0 ? (
                <div
                    className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-8 text-center text-sm"
                    data-test="ssh-needs-key"
                >
                    <KeyRound className="size-6 text-muted-foreground" />
                    {data.is_owner ? (
                        <>
                            <p>
                                You need to add an SSH key to your account
                                before connecting.
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onManageKeys}
                                className="mt-2"
                            >
                                Go to Keys
                            </Button>
                        </>
                    ) : (
                        <p>
                            {data.owner_name} needs to add an SSH key. Sandboxes
                            accept their owner&apos;s keys.
                        </p>
                    )}
                </div>
            ) : (
                <>
                    <Step
                        number={1}
                        title="Add this to your SSH config"
                        description={
                            <>
                                Paste it into{' '}
                                <code className="font-mono">~/.ssh/config</code>
                                . Host key checks are off because each sandbox
                                makes its own key and the address only works on
                                this machine. The port changes when the sandbox
                                is recreated; come back here to update it.
                            </>
                        }
                    >
                        <Snippet text={config} testId="ssh-config" />
                    </Step>
                    <Step number={2} title="Connect">
                        <div className="flex flex-wrap gap-2">
                            <Button asChild data-test="ssh-open-vscode">
                                <a href={`vscode://vscode-remote/${remote}`}>
                                    <ExternalLink /> Open in VS Code
                                </a>
                            </Button>
                            <Button
                                asChild
                                variant="outline"
                                data-test="ssh-open-cursor"
                            >
                                <a href={`cursor://vscode-remote/${remote}`}>
                                    <ExternalLink /> Open in Cursor
                                </a>
                            </Button>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Needs the Remote - SSH extension. Or from a
                            terminal:
                        </p>
                        <Snippet text={`ssh ${alias}`} testId="ssh-command" />
                    </Step>
                    {!data.is_owner && (
                        <p className="text-sm text-muted-foreground">
                            This sandbox accepts {data.owner_name}&apos;s SSH
                            keys.
                        </p>
                    )}
                </>
            )}

            <a
                href="https://code.visualstudio.com/docs/remote/ssh"
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-2 text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                <BookOpen className="size-4" /> About Remote - SSH
            </a>
        </div>
    );
}

function Step({
    number,
    title,
    description,
    children,
}: {
    number: number;
    title: string;
    description?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-2 rounded-lg border p-4">
            <h4 className="flex items-center gap-2 text-sm font-medium">
                <span className="flex size-5 items-center justify-center rounded-full bg-muted text-xs">
                    {number}
                </span>
                {title}
            </h4>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
            {children}
        </section>
    );
}

function Snippet({ text, testId }: { text: string; testId: string }) {
    return (
        <div className="relative">
            <pre
                className="overflow-x-auto rounded-md bg-muted px-3 py-2 pr-10 font-mono text-xs leading-relaxed"
                data-test={testId}
            >
                {text}
            </pre>
            <div className="absolute top-1 right-1">
                <CopyButton text={text} label="Copy" />
            </div>
        </div>
    );
}

function SshKeysPage({ projectId }: { projectId: number }) {
    const { data, error, reload } = useJson<{ keys: SshKey[] }>(
        SshKeyController.index.url(),
    );
    const ssh = useJson<Ssh>(ProjectDeveloperController.ssh.url(projectId));
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<SshKey | null>(null);

    const changed = () => {
        void reload();
        void ssh.reload();
    };

    return (
        <div className="space-y-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h3 className="font-medium">Manage SSH keys</h3>
                    <p className="text-sm text-muted-foreground">
                        Public keys on your account. Your projects&apos;
                        sandboxes accept them for SSH.
                    </p>
                </div>
                <Button onClick={() => setAdding(true)} data-test="ssh-key-add">
                    <Plus /> Add SSH key
                </Button>
            </div>

            {ssh.data && !ssh.data.is_owner && (
                <p className="text-sm text-muted-foreground">
                    This project belongs to {ssh.data.owner_name}; its sandbox
                    accepts their keys, not these.
                </p>
            )}

            {error && !data ? (
                <Empty tone="error">{error}</Empty>
            ) : !data ? (
                <Empty>Loading…</Empty>
            ) : data.keys.length === 0 ? (
                <div
                    className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-8 text-sm text-muted-foreground"
                    data-test="ssh-keys-empty"
                >
                    <KeyRound className="size-6" />
                    You haven&apos;t added any SSH keys yet.
                </div>
            ) : (
                <ul className="divide-y rounded-lg border" data-test="ssh-keys">
                    {data.keys.map((key) => (
                        <li
                            key={key.id}
                            className="flex items-center gap-3 px-4 py-3"
                            data-test="ssh-key"
                        >
                            <KeyRound className="size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {key.name}
                                </p>
                                <p className="font-mono text-xs break-all text-muted-foreground">
                                    {key.type} · {key.fingerprint}
                                </p>
                            </div>
                            {key.created_at && (
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    Added{' '}
                                    {new Date(
                                        key.created_at,
                                    ).toLocaleDateString()}
                                </span>
                            )}
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                aria-label={`Delete ${key.name}`}
                                onClick={() => setDeleting(key)}
                                data-test="ssh-key-delete"
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {adding && (
                <AddKeyDialog
                    onClose={() => setAdding(false)}
                    onAdded={() => {
                        setAdding(false);
                        changed();
                    }}
                />
            )}
            <DeleteKeyDialog
                sshKey={deleting}
                onClose={() => setDeleting(null)}
                onDeleted={() => {
                    setDeleting(null);
                    changed();
                }}
            />
        </div>
    );
}

function AddKeyDialog({
    onClose,
    onAdded,
}: {
    onClose: () => void;
    onAdded: () => void;
}) {
    const [name, setName] = useState('');
    const [publicKey, setPublicKey] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const save = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        jsonRequest(SshKeyController.store.url(), {
            name,
            public_key: publicKey,
        })
            .then(onAdded)
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg" data-test="ssh-key-dialog">
                <DialogHeader>
                    <DialogTitle>Add SSH key</DialogTitle>
                    <DialogDescription>
                        Paste a public key, like the contents of{' '}
                        <code>~/.ssh/id_ed25519.pub</code>. No key yet? Run{' '}
                        <code>ssh-keygen -t ed25519</code> in a terminal.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={save} className="space-y-3">
                    <div className="space-y-1">
                        <Label htmlFor="ssh-key-value">Public key</Label>
                        <textarea
                            id="ssh-key-value"
                            value={publicKey}
                            onChange={(event) =>
                                setPublicKey(event.target.value)
                            }
                            rows={4}
                            placeholder="ssh-ed25519 AAAA… you@laptop"
                            autoComplete="off"
                            spellCheck={false}
                            required
                            autoFocus
                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            data-test="ssh-key-input"
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="ssh-key-name">Name (optional)</Label>
                        <Input
                            id="ssh-key-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="Work laptop"
                            maxLength={100}
                            data-test="ssh-key-name"
                        />
                    </div>
                    {error && (
                        <p
                            className="text-sm text-red-600"
                            data-test="ssh-key-error"
                        >
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={saving || publicKey.trim() === ''}
                            data-test="ssh-key-save"
                        >
                            Add key
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteKeyDialog({
    sshKey,
    onClose,
    onDeleted,
}: {
    sshKey: SshKey | null;
    onClose: () => void;
    onDeleted: () => void;
}) {
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => setError(null), [sshKey]);

    const remove = () => {
        if (!sshKey) {
            return;
        }

        setDeleting(true);
        jsonRequest(SshKeyController.destroy.url(sshKey.id), {}, 'DELETE')
            .then(onDeleted)
            .catch((e: Error) => setError(e.message))
            .finally(() => setDeleting(false));
    };

    return (
        <Dialog
            open={sshKey !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent
                className="sm:max-w-md"
                data-test="ssh-key-delete-dialog"
            >
                <DialogHeader>
                    <DialogTitle>Delete {sshKey?.name}?</DialogTitle>
                    <DialogDescription>
                        Your sandboxes stop accepting this key. Anyone still
                        connected with it stays connected until they disconnect.
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
                        data-test="ssh-key-delete-confirm"
                    >
                        <Trash2 /> Delete key
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function CopyButton({ text, label }: { text: string; label: string }) {
    const [copied, copy] = useClipboard();

    return (
        <Button
            size="icon"
            variant="ghost"
            className="size-7 shrink-0"
            aria-label={label}
            title={label}
            onClick={() => void copy(text)}
        >
            {copied === text ? <Check className="text-green-600" /> : <Copy />}
        </Button>
    );
}

function Empty({ children, tone }: { children: ReactNode; tone?: 'error' }) {
    return (
        <div
            className={cn(
                'rounded-lg border border-dashed p-6 text-center text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
            )}
            data-test="developer-empty-state"
        >
            {children}
        </div>
    );
}
