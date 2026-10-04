import { Check, Copy, EllipsisVertical } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import ProjectDomainController from '@/actions/App/Http/Controllers/ProjectDomainController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import type { Publication } from '@/types';

type DnsRecord = { type: string; name: string; value: string };

type Domain = {
    id: number;
    hostname: string;
    primary: boolean;
    status: 'pending' | 'active' | 'not_connected';
    error: string | null;
    records: DnsRecord[];
    checked_at: string | null;
};

type Domains = {
    domains: Domain[];
    /** Why domains can't be connected where the project is published. */
    unavailable: string | null;
    /** Whether the other addresses redirect to the primary domain. */
    redirects: boolean;
    url: string | null;
};

/** How often a waiting domain's status is refreshed while the panel is open. */
const POLL_MS = 10_000;

/**
 * Domains (DOM-001..004): add domains the user owns, see the DNS records to add and whether each one is active, and
 * choose the primary one.
 */
export default function DomainsPanel({
    projectId,
    publication,
}: {
    projectId: number;
    publication: Publication;
}) {
    const [data, setData] = useState<Domains | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const load = useCallback(
        () =>
            jsonRequest<Domains>(ProjectDomainController.index.url(projectId))
                .then((next) => {
                    setData(next);
                    setError(null);
                })
                .catch((e: Error) => setError(e.message)),
        [projectId],
    );

    // Reload when the project is published somewhere else, or goes live.
    useEffect(() => {
        void load();
    }, [load, publication.status, publication.target]);

    const waiting = data?.domains.some((d) => d.status === 'pending');

    useEffect(() => {
        if (!waiting) {
            return;
        }

        const timer = window.setInterval(load, POLL_MS);

        return () => window.clearInterval(timer);
    }, [waiting, load]);

    const act = (request: Promise<Domains>) =>
        request
            .then((next) => {
                setData(next);
                setNotice(null);
            })
            .catch((e: Error) => setNotice(e.message));

    if (error) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!data) {
        return <Empty>Loading…</Empty>;
    }

    return (
        <div className="max-w-3xl space-y-6" data-test="domains-panel">
            {data.unavailable && (
                <p
                    className="rounded-xl border border-dashed border-sidebar-border p-4 text-sm text-muted-foreground"
                    data-test="domains-unavailable"
                >
                    {data.unavailable}
                </p>
            )}

            <AddDomain
                onAdd={(hostname) =>
                    jsonRequest<Domains>(
                        ProjectDomainController.store.url(projectId),
                        { hostname },
                    ).then(setData)
                }
            />

            {notice && (
                <p className="text-sm text-red-600" data-test="domains-notice">
                    {notice}
                </p>
            )}

            {data.domains.length === 0 ? (
                <Empty>
                    No domains yet. Add one you own, like example.com or
                    app.example.com, and point its DNS here.
                </Empty>
            ) : (
                <ul className="space-y-3" data-test="domains-list">
                    {data.domains.map((domain) => (
                        <DomainRow
                            key={domain.id}
                            domain={domain}
                            onCheck={() =>
                                act(
                                    jsonRequest<Domains>(
                                        ProjectDomainController.check.url({
                                            project: projectId,
                                            domain: domain.id,
                                        }),
                                        {},
                                    ),
                                )
                            }
                            onMakePrimary={() =>
                                act(
                                    jsonRequest<Domains>(
                                        ProjectDomainController.update.url({
                                            project: projectId,
                                            domain: domain.id,
                                        }),
                                        { primary: true },
                                        'PATCH',
                                    ),
                                )
                            }
                            onRemove={() =>
                                window.confirm(
                                    `Remove ${domain.hostname}? It stops serving the app.`,
                                ) &&
                                act(
                                    jsonRequest<Domains>(
                                        ProjectDomainController.destroy.url({
                                            project: projectId,
                                            domain: domain.id,
                                        }),
                                        {},
                                        'DELETE',
                                    ),
                                )
                            }
                        />
                    ))}
                </ul>
            )}

            <p className="text-xs text-muted-foreground">
                {data.redirects
                    ? "Once the primary domain is active, it's the app's URL, and its own address and other domains redirect to it."
                    : "Once the primary domain is active, it's the app's URL. Every domain serves the app."}{' '}
                Certificates are issued automatically.
            </p>
        </div>
    );
}

function AddDomain({ onAdd }: { onAdd: (hostname: string) => Promise<void> }) {
    const [hostname, setHostname] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSending(true);

        onAdd(hostname)
            .then(() => {
                setHostname('');
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setSending(false));
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-2 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <label htmlFor="domain-hostname" className="text-sm font-medium">
                Add a domain
            </label>
            <div className="flex gap-2">
                <Input
                    id="domain-hostname"
                    value={hostname}
                    onChange={(event) => setHostname(event.target.value)}
                    placeholder="example.com"
                    autoComplete="off"
                    spellCheck={false}
                    data-test="domain-hostname"
                />
                <Button
                    type="submit"
                    disabled={sending || hostname.trim() === ''}
                    data-test="domain-add"
                >
                    Add
                </Button>
            </div>
            {error && (
                <p className="text-sm text-red-600" data-test="domain-error">
                    {error}
                </p>
            )}
        </form>
    );
}

function DomainRow({
    domain,
    onCheck,
    onMakePrimary,
    onRemove,
}: {
    domain: Domain;
    onCheck: () => void;
    onMakePrimary: () => void;
    onRemove: () => void;
}) {
    // Test ids can't have dots in them.
    const testId = domain.hostname.replaceAll('.', '-');
    const label =
        domain.status === 'active'
            ? 'Active'
            : domain.status === 'pending'
              ? 'Waiting for DNS'
              : 'Not connected';

    return (
        <li
            className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            data-test={`domain-${testId}`}
        >
            <div className="flex items-center gap-3">
                <span
                    className={cn(
                        'size-2 shrink-0 rounded-full',
                        domain.status === 'active'
                            ? 'bg-green-500'
                            : domain.status === 'pending'
                              ? 'bg-amber-500'
                              : 'bg-muted-foreground/40',
                    )}
                />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {domain.status === 'active' ? (
                            <a
                                href={`https://${domain.hostname}`}
                                target="_blank"
                                rel="noreferrer"
                                className="underline-offset-4 hover:underline"
                            >
                                {domain.hostname}
                            </a>
                        ) : (
                            domain.hostname
                        )}
                        {domain.primary && (
                            <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs font-normal text-muted-foreground">
                                Primary
                            </span>
                        )}
                    </p>
                    <p
                        className="text-xs text-muted-foreground"
                        data-test={`domain-status-${testId}`}
                    >
                        {label}
                    </p>
                </div>
                {domain.status !== 'active' && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={onCheck}
                        data-test={`domain-check-${testId}`}
                    >
                        Check now
                    </Button>
                )}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            aria-label={`More options for ${domain.hostname}`}
                            data-test={`domain-menu-${testId}`}
                        >
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {!domain.primary && (
                            <DropdownMenuItem
                                onSelect={onMakePrimary}
                                data-test={`domain-primary-${testId}`}
                            >
                                Make primary
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            onSelect={onRemove}
                            data-test={`domain-remove-${testId}`}
                        >
                            Remove
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            {domain.error && domain.status !== 'active' && (
                <p
                    className="text-sm text-muted-foreground"
                    data-test={`domain-problem-${testId}`}
                >
                    {domain.error}
                </p>
            )}

            {domain.status !== 'active' && domain.records.length > 0 && (
                <Records records={domain.records} />
            )}
        </li>
    );
}

/** The DNS records to add at the domain's DNS host, each with a copy button for its value. */
function Records({ records }: { records: DnsRecord[] }) {
    const [copied, copy] = useClipboard();

    return (
        <div>
            <p className="mb-2 text-xs text-muted-foreground">
                Add {records.length === 1 ? 'this record' : 'these records'}{' '}
                where your domain's DNS is managed:
            </p>
            <table
                className="w-full table-fixed text-left text-sm"
                data-test="domain-records"
            >
                <thead className="text-xs text-muted-foreground">
                    <tr>
                        <th className="w-14 pb-1 font-normal">Type</th>
                        <th className="pb-1 font-normal">Name</th>
                        <th className="pb-1 font-normal">Value</th>
                    </tr>
                </thead>
                <tbody className="font-mono text-xs">
                    {records.map((record) => (
                        <tr
                            key={`${record.type}-${record.value}`}
                            className="border-t border-sidebar-border/70 dark:border-sidebar-border"
                        >
                            <td className="py-1.5">{record.type}</td>
                            <td className="py-1.5 pr-2 break-all">
                                {record.name}
                            </td>
                            <td className="py-1.5 align-top">
                                <span className="flex items-start gap-1">
                                    <span className="break-all">
                                        {record.value}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => copy(record.value)}
                                        aria-label={`Copy ${record.value}`}
                                        className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted"
                                    >
                                        {copied === record.value ? (
                                            <Check className="size-3.5" />
                                        ) : (
                                            <Copy className="size-3.5" />
                                        )}
                                    </button>
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {records[0]?.type === 'CNAME' &&
                records[0].name.split('.').length === 2 && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        For a root domain, use your DNS host's CNAME flattening,
                        ALIAS or ANAME record.
                    </p>
                )}
        </div>
    );
}

function Empty({
    children,
    tone,
}: {
    children: React.ReactNode;
    tone?: 'error';
}) {
    return (
        <div
            className={cn(
                'rounded-xl border border-dashed border-sidebar-border p-6 text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
            )}
            data-test="domains-empty"
        >
            {children}
        </div>
    );
}
