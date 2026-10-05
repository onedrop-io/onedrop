import {
    Check,
    Cloud,
    Copy,
    Download,
    ExternalLink,
    Laptop,
    Network,
    Plus,
    SquareTerminal,
    Trash2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ProjectComputerController from '@/actions/App/Http/Controllers/ProjectComputerController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useClipboard } from '@/hooks/use-clipboard';
import { useDesktopDownload } from '@/hooks/use-desktop-download';
import { useOnedropDesktop } from '@/hooks/use-onedrop-desktop';
import { jsonRequest } from '@/lib/json-request';
import { DESKTOP_DOCS_URL } from '@/lib/links';
import { cn } from '@/lib/utils';
import type {
    DesktopDockerStatus,
    DesktopForward,
    DesktopNetworkStatus,
    OnedropDesktop,
} from '@/types/desktop';

type NetworkHost = { host: string; port: number };

type Computer = {
    can_update: boolean;
    is_owner: boolean;
    owner_name: string;
    host_alias: string;
    running: boolean;
    ports: { port: number; label: string }[];
    network_hosts: NetworkHost[];
    device: { id: number; name: string; this: boolean } | null;
    devices_available: boolean;
    moving: boolean;
    move_error: string | null;
};

/**
 * Tools → This computer (DESK-006): the project's ports on this computer, opening it in an editor, reaching the user's
 * network, and where it runs. The desktop app does the work; a browser gets what it would do and a download.
 */
export default function ComputerPanel({
    projectId,
    running,
}: {
    projectId: number;
    running: boolean;
}) {
    const { desktop, changes } = useOnedropDesktop();
    const [data, setData] = useState<Computer | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(
        () =>
            jsonRequest<Computer>(ProjectComputerController.show.url(projectId))
                .then((computer) => {
                    setData(computer);
                    setError(null);
                })
                .catch((e: Error) => setError(e.message)),
        [projectId],
    );

    useEffect(() => {
        void load();
    }, [load, running]);

    // While it moves to or from a computer, check on it every few seconds.
    useEffect(() => {
        if (!data?.moving) {
            return;
        }

        const timer = window.setInterval(() => void load(), 3000);

        return () => window.clearInterval(timer);
    }, [data?.moving, load]);

    if (!desktop) {
        return <DesktopPitch projectId={projectId} />;
    }

    if (error && !data) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!data) {
        return <Empty>Loading…</Empty>;
    }

    return (
        <div className="max-w-2xl space-y-4" data-test="computer-panel">
            <WhereItRuns
                desktop={desktop}
                changes={changes}
                projectId={projectId}
                data={data}
                onMoved={load}
            />
            <Ports
                desktop={desktop}
                changes={changes}
                projectId={projectId}
                data={data}
            />
            <Editor desktop={desktop} projectId={projectId} data={data} />
            <YourNetwork
                desktop={desktop}
                changes={changes}
                projectId={projectId}
                data={data}
                onSaved={(hosts) =>
                    setData((current) =>
                        current
                            ? { ...current, network_hosts: hosts }
                            : current,
                    )
                }
            />
        </div>
    );
}

/** What the panel does in the desktop app, for someone in a browser. */
function DesktopPitch({ projectId }: { projectId: number }) {
    const download = useDesktopDownload();

    return (
        <div className="max-w-xl space-y-4" data-test="computer-pitch">
            <p className="text-sm">
                In the OneDrop desktop app, this project can use your computer:
            </p>
            <ul className="space-y-2 text-sm text-muted-foreground">
                <li>
                    <strong className="text-foreground">Ports</strong>: the app
                    and its database at <code>localhost</code>, for your
                    browser, TablePlus or <code>psql</code>.
                </li>
                <li>
                    <strong className="text-foreground">Your editor</strong>:
                    open it in VS Code or Cursor with one click.
                </li>
                <li>
                    <strong className="text-foreground">Your network</strong>:
                    let the agent and the preview reach hosts only your computer
                    can, like an intranet or a database behind a VPN.
                </li>
                <li>
                    <strong className="text-foreground">Run it here</strong>:
                    move the project to your computer&apos;s Docker.
                </li>
            </ul>
            <div className="flex flex-wrap items-center gap-2">
                <Button asChild data-test="computer-download">
                    <a href={download.href}>
                        <Download /> {download.label}
                    </a>
                </Button>
                <Button asChild variant="outline">
                    <a href={`onedrop://projects/${projectId}`}>
                        <ExternalLink /> Open in the desktop app
                    </a>
                </Button>
            </div>
            <a
                href={DESKTOP_DOCS_URL}
                target="_blank"
                rel="noreferrer"
                className="text-sm text-muted-foreground underline underline-offset-4"
            >
                Other systems and the guide
            </a>
        </div>
    );
}

function WhereItRuns({
    desktop,
    changes,
    projectId,
    data,
    onMoved,
}: {
    desktop: OnedropDesktop;
    changes: number;
    projectId: number;
    data: Computer;
    onMoved: () => Promise<void>;
}) {
    const [docker, setDocker] = useState<DesktopDockerStatus | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const here = data.device?.this === true;

    useEffect(() => {
        void desktop.docker.status().then(setDocker);
    }, [desktop, changes]);

    const move = async (to: 'this' | 'cloud') => {
        setBusy(true);
        setError(null);

        try {
            if (to === 'this') {
                const status = await desktop.docker.status();

                if (status.state !== 'ready') {
                    setDocker(status);

                    return;
                }

                if (!status.hasImage) {
                    setDocker(await desktop.docker.prepare());
                }
            }

            await jsonRequest(
                ProjectComputerController.device.url(projectId),
                { to },
                'PUT',
            );
            await onMoved();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    if (!data.devices_available && !data.device) {
        return null;
    }

    return (
        <Section
            icon={data.device ? Laptop : Cloud}
            title="Where it runs"
            test="computer-where"
        >
            <p className="text-sm">
                {data.moving
                    ? data.device
                        ? `Moving to ${data.device.name}…`
                        : 'Moving back to OneDrop’s cloud…'
                    : data.device
                      ? here
                          ? `On this computer (${data.device.name}), in Docker.`
                          : `On ${data.device.name}. It works while the OneDrop app is open there.`
                      : 'In OneDrop’s cloud.'}
            </p>
            {data.move_error && !data.moving && (
                <p className="text-sm text-red-600">{data.move_error}</p>
            )}
            {error && <p className="text-sm text-red-600">{error}</p>}
            {docker && docker.state !== 'ready' && !data.device && (
                <p
                    className="text-sm text-muted-foreground"
                    data-test="computer-docker"
                >
                    {docker.state === 'missing'
                        ? 'Running projects here needs Docker: install Docker Desktop, OrbStack or Colima, then come back.'
                        : docker.state === 'stopped'
                          ? 'Docker is installed but not running. Start it, then try again.'
                          : docker.state === 'pulling'
                            ? `Downloading the sandbox image${docker.progress !== null ? ` (${Math.round(docker.progress * 100)}%)` : ''}…`
                            : 'Checking for Docker…'}
                    {docker.detail && ` ${docker.detail}`}
                </p>
            )}
            {data.is_owner && !data.moving && (
                <div>
                    {data.device ? (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => void move('cloud')}
                            data-test="computer-move-cloud"
                        >
                            <Cloud /> Move back to the cloud
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            disabled={busy || docker?.state === 'pulling'}
                            onClick={() => void move('this')}
                            data-test="computer-move-here"
                        >
                            <Laptop /> Run it on this computer
                        </Button>
                    )}
                </div>
            )}
            {!data.device && (
                <p className="text-xs text-muted-foreground">
                    Its files come along. Teammates still open its preview
                    through OneDrop while this app is open.
                </p>
            )}
        </Section>
    );
}

function Ports({
    desktop,
    changes,
    projectId,
    data,
}: {
    desktop: OnedropDesktop;
    changes: number;
    projectId: number;
    data: Computer;
}) {
    const [forwards, setForwards] = useState<DesktopForward[]>([]);
    const [custom, setCustom] = useState('');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        void desktop.forwards.list(projectId).then(setForwards);
    }, [desktop, projectId, changes]);

    if (!data.can_update) {
        return null;
    }

    const start = (port: number) => {
        setError(null);
        desktop.forwards
            .start(projectId, port)
            .then(() => desktop.forwards.list(projectId))
            .then(setForwards)
            .catch((e: Error) => setError(e.message));
    };

    const add = (event: FormEvent) => {
        event.preventDefault();
        const port = Number(custom);

        if (Number.isInteger(port) && port > 0 && port < 65536) {
            start(port);
            setCustom('');
        } else {
            setError('A port is a number from 1 to 65535.');
        }
    };

    const label = (port: number) =>
        data.ports.find((suggestion) => suggestion.port === port)?.label ??
        `Port ${port}`;
    const suggestions = data.ports.filter(
        (suggestion) =>
            !forwards.some((forward) => forward.remotePort === suggestion.port),
    );

    return (
        <Section
            icon={Network}
            title="Ports on this computer"
            test="computer-ports"
        >
            <p className="text-sm text-muted-foreground">
                Reach the sandbox&apos;s ports at <code>localhost</code>, while
                this app is open, even with its window closed.
            </p>
            {forwards.length > 0 && (
                <ul className="divide-y rounded-md border text-sm">
                    {forwards.map((forward) => (
                        <li
                            key={forward.remotePort}
                            className="flex items-center gap-3 px-3 py-2"
                            data-test="computer-forward"
                        >
                            <span
                                className={cn(
                                    'size-2 shrink-0 rounded-full',
                                    forward.state === 'listening'
                                        ? 'bg-green-500'
                                        : forward.state === 'error'
                                          ? 'bg-red-500'
                                          : 'bg-amber-500',
                                )}
                            />
                            <span className="min-w-0 flex-1">
                                <span className="font-medium">
                                    {label(forward.remotePort)}
                                </span>{' '}
                                <code className="text-muted-foreground">
                                    localhost:{forward.localPort}
                                </code>
                                {forward.error && (
                                    <span className="block text-xs text-red-600">
                                        {forward.error}
                                    </span>
                                )}
                            </span>
                            {forward.remotePort === data.ports[0]?.port && (
                                <Button asChild variant="ghost" size="sm">
                                    <a
                                        href={`http://localhost:${forward.localPort}`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ExternalLink /> Open
                                    </a>
                                </Button>
                            )}
                            <CopyButton
                                text={`localhost:${forward.localPort}`}
                                label="Copy the address"
                            />
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                aria-label="Stop forwarding"
                                title="Stop forwarding"
                                onClick={() =>
                                    void desktop.forwards
                                        .stop(projectId, forward.remotePort)
                                        .then(() =>
                                            desktop.forwards.list(projectId),
                                        )
                                        .then(setForwards)
                                }
                            >
                                <X />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {suggestions.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {suggestions.map((suggestion) => (
                        <Button
                            key={suggestion.port}
                            variant="outline"
                            size="sm"
                            disabled={!data.running}
                            onClick={() => start(suggestion.port)}
                            data-test={`computer-forward-${suggestion.port}`}
                        >
                            <Plus /> {suggestion.label}{' '}
                            <span className="text-muted-foreground">
                                {suggestion.port}
                            </span>
                        </Button>
                    ))}
                </div>
            )}
            <form onSubmit={add} className="flex items-center gap-2">
                <Input
                    value={custom}
                    onChange={(event) => setCustom(event.target.value)}
                    inputMode="numeric"
                    placeholder="Another port, e.g. 5432"
                    aria-label="Port to forward"
                    className="h-8 max-w-48"
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={!custom}
                >
                    Forward
                </Button>
            </form>
            {error && <p className="text-sm text-red-600">{error}</p>}
        </Section>
    );
}

function Editor({
    desktop,
    projectId,
    data,
}: {
    desktop: OnedropDesktop;
    projectId: number;
    data: Computer;
}) {
    const [error, setError] = useState<string | null>(null);
    const [opening, setOpening] = useState(false);

    const open = (editor: 'vscode' | 'cursor') => {
        setOpening(true);
        setError(null);
        desktop.editor
            .open(projectId, data.host_alias, editor)
            .catch((e: Error) => setError(e.message))
            .finally(() => setOpening(false));
    };

    return (
        <Section
            icon={SquareTerminal}
            title="Open in your editor"
            test="computer-editor"
        >
            {data.is_owner ? (
                <>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            disabled={opening || !data.running}
                            onClick={() => open('vscode')}
                            data-test="computer-open-vscode"
                        >
                            <ExternalLink /> Open in VS Code
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={opening || !data.running}
                            onClick={() => open('cursor')}
                            data-test="computer-open-cursor"
                        >
                            <ExternalLink /> Open in Cursor
                        </Button>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Needs the Remote - SSH extension. The app adds its own
                        SSH key and config the first time (asking before it
                        touches <code>~/.ssh/config</code>). From a terminal:{' '}
                        <code>ssh {data.host_alias}</code>
                    </p>
                    {error && <p className="text-sm text-red-600">{error}</p>}
                </>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Only {data.owner_name} can open it in an editor: the sandbox
                    accepts its owner&apos;s keys.
                </p>
            )}
        </Section>
    );
}

function YourNetwork({
    desktop,
    changes,
    projectId,
    data,
    onSaved,
}: {
    desktop: OnedropDesktop;
    changes: number;
    projectId: number;
    data: Computer;
    onSaved: (hosts: NetworkHost[]) => void;
}) {
    const [status, setStatus] = useState<DesktopNetworkStatus | null>(null);
    const [adding, setAdding] = useState('');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        void desktop.network.status(projectId).then(setStatus);
    }, [desktop, projectId, changes]);

    const save = (hosts: NetworkHost[]) => {
        setError(null);
        jsonRequest<{ network_hosts: NetworkHost[] }>(
            ProjectComputerController.network.url(projectId),
            { hosts },
            'PUT',
        )
            .then(({ network_hosts }) => {
                onSaved(network_hosts);

                // Saved here, by this computer's user: it agrees to the new list too.
                if (status?.sharing) {
                    return desktop.network
                        .setSharing(projectId, true)
                        .then(setStatus);
                }
            })
            .catch((e: Error) => setError(e.message));
    };

    const add = (event: FormEvent) => {
        event.preventDefault();
        const match = adding.trim().match(/^(\[[^\]]+\]|[^:\s/]+):(\d+)$/);

        if (!match) {
            setError('Write it as host:port, like db.internal:5432.');

            return;
        }

        save([
            ...data.network_hosts,
            { host: match[1], port: Number(match[2]) },
        ]);
        setAdding('');
    };

    if (data.device?.this) {
        return (
            <Section
                icon={Network}
                title="Your network"
                test="computer-network"
            >
                <p className="text-sm text-muted-foreground">
                    It runs on this computer, so it reaches your network
                    directly.
                </p>
            </Section>
        );
    }

    return (
        <Section icon={Network} title="Your network" test="computer-network">
            <p className="text-sm text-muted-foreground">
                Hosts only your computer can reach (an intranet, a database
                behind a VPN, something on <code>localhost</code>). The agent,
                the Shell and the preview reach them through this app while
                it&apos;s open. Published apps on OneDrop&apos;s hosting
                can&apos;t; self-host or publish to your tailnet for that.
            </p>
            {data.network_hosts.length > 0 && (
                <ul className="divide-y rounded-md border text-sm">
                    {data.network_hosts.map((host) => (
                        <li
                            key={`${host.host}:${host.port}`}
                            className="flex items-center gap-3 px-3 py-2"
                            data-test="computer-network-host"
                        >
                            <code className="min-w-0 flex-1 truncate">
                                {host.host}:{host.port}
                            </code>
                            {data.can_update && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    aria-label={`Remove ${host.host}:${host.port}`}
                                    onClick={() =>
                                        save(
                                            data.network_hosts.filter(
                                                (other) => other !== host,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {data.can_update && (
                <form onSubmit={add} className="flex items-center gap-2">
                    <Input
                        value={adding}
                        onChange={(event) => setAdding(event.target.value)}
                        placeholder="db.internal:5432"
                        aria-label="Host and port"
                        className="h-8 max-w-64"
                        data-test="computer-network-input"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={!adding.trim()}
                    >
                        <Plus /> Add
                    </Button>
                </form>
            )}
            {error && <p className="text-sm text-red-600">{error}</p>}
            {data.can_update && data.network_hosts.length > 0 && status && (
                <div className="flex items-center gap-3 text-sm">
                    <Switch
                        checked={status.sharing}
                        onChange={(sharing) =>
                            void desktop.network
                                .setSharing(projectId, sharing)
                                .then(setStatus)
                        }
                        label="Share this computer's network with the project"
                        testId="computer-network-share"
                    />
                    <span className="flex-1">
                        Share this computer&apos;s network with the project
                        <span className="block text-xs text-muted-foreground">
                            {status.state === 'connected'
                                ? `Connected: the sandbox reaches these hosts through ${desktop.device.name}.${status.error ? ` ${status.error}` : ''}`
                                : status.state === 'connecting'
                                  ? 'Connecting…'
                                  : status.state === 'error'
                                    ? (status.error ?? 'Couldn’t connect.')
                                    : 'Off on this computer.'}
                        </span>
                    </span>
                </div>
            )}
        </Section>
    );
}

function Section({
    icon: Icon,
    title,
    test,
    children,
}: {
    icon: typeof Laptop;
    title: string;
    test: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-lg border p-4" data-test={test}>
            <h3 className="flex items-center gap-2 font-medium">
                <Icon className="size-4 text-muted-foreground" />
                {title}
            </h3>
            {children}
        </section>
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
        >
            {children}
        </div>
    );
}
