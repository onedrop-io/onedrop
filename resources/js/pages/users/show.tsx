import { Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ImpersonationController from '@/actions/App/Http/Controllers/ImpersonationController';
import UserController from '@/actions/App/Http/Controllers/UserController';
import AreaLinesChart from '@/components/charts/area-lines-chart';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    formatCost,
    formatTokens,
    HARNESS_COLORS,
    HARNESS_LABELS,
} from '@/lib/usage';
import { show as showProject } from '@/routes/projects';
import { index } from '@/routes/users';
import type { AgentHarness } from '@/types/agents';

type User = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    is_admin: boolean;
    email_verified_at: string | null;
    has_password: boolean;
    two_factor_enabled: boolean;
    passkeys_count: number;
    last_login_at: string | null;
    last_login_method: string | null;
    created_at: string | null;
    updated_at: string | null;
};

type Organization = {
    id: number;
    name: string;
    role: string;
    is_current: boolean;
    joined_at: string | null;
};

type Group = { id: number; name: string; organization: string; role: string };

type Project = {
    id: number;
    name: string;
    organization: string;
    status: string;
    agent: string | null;
    model: string | null;
    messages_count: number;
    tasks_count: number;
    sandbox: { provider: string; status: string; error: string | null } | null;
    published_url: string | null;
    publish_error: string | null;
    git_remote_url: string | null;
    git_sync_error: string | null;
    archived: boolean;
    can_open: boolean;
    created_at: string | null;
    updated_at: string | null;
};

type Skill = {
    id: number;
    name: string;
    organization: string;
    shared: boolean;
};

type SignInMethod = {
    id: number;
    provider: string;
    email: string | null;
    created_at: string | null;
};

type SshKey = {
    id: number;
    name: string;
    fingerprint: string;
    created_at: string | null;
};

type AiConnection = {
    id: number;
    provider: string;
    type: string;
    hint: string;
    is_default: boolean;
    verified_at: string | null;
};

type GitHub = {
    login: string | null;
    installations: { id: number; account: string; type: string }[];
};

type Sums = { cost: number; tokens: number; sessions: number };

type LifetimeUsage = Sums & {
    input: number;
    output: number;
    runs: number;
    last_run_at: string | null;
};

/** The past 30 days, as on the user's own Usage page (UsageReport). */
type Usage = {
    totals: Sums;
    agents: (Sums & { harness: AgentHarness; label: string })[];
    models: (Sums & { harness: AgentHarness; name: string })[];
    projects: (Sums & { id: number | null; name: string })[];
    series: { t: number; cost: Partial<Record<AgentHarness, number>> }[];
};

type InvitedBy = {
    name: string | null;
    email: string | null;
    organization: string;
    accepted_at: string | null;
};

type ImpersonationRecord = {
    id: number;
    admin: string | null;
    ip_address: string | null;
    started_at: string;
    ended_at: string | null;
};

type InvitationSent = {
    id: number;
    email: string | null;
    organization: string;
    status: string;
    accepted_by: string | null;
    created_at: string | null;
};

type Session = {
    ip_address: string | null;
    user_agent: string | null;
    last_active_at: string;
};

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '—';
}

function Section({
    title,
    count,
    children,
}: {
    title: string;
    count?: number;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3">
            <Heading
                variant="small"
                title={count === undefined ? title : `${title} (${count})`}
            />
            {children}
        </section>
    );
}

function Rows({ children }: { children: ReactNode }) {
    return (
        <ul className="divide-y rounded-xl border border-sidebar-border/70 text-sm dark:border-sidebar-border">
            {children}
        </ul>
    );
}

function Row({ children }: { children: ReactNode }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-3 p-3">
            {children}
        </li>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}

const SIGN_IN_METHODS: Record<string, string> = {
    password: 'Password',
    passkey: 'Passkey',
    google: 'Google',
    microsoft: 'Microsoft',
    github: 'GitHub',
    gitlab: 'GitLab',
    oidc: 'Single sign-on',
};

/** An admin action behind a confirmation (USR-002). */
function ConfirmAction({
    label,
    title,
    description,
    href,
    testId,
}: {
    label: string;
    title: string;
    description: string;
    href: { url: string; method: 'post' | 'delete' };
    testId: string;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" data-test={testId}>
                    {label}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <DialogClose asChild>
                        <Button variant="destructive" asChild>
                            <Link
                                href={href}
                                as="button"
                                preserveScroll
                                data-test={`${testId}-confirm`}
                            >
                                {label}
                            </Link>
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ProjectError({ label, message }: { label: string; message: string }) {
    return (
        <p className="mt-1 text-destructive">
            {label}: {message}
        </p>
    );
}

function Facts({ facts }: { facts: [string, ReactNode][] }) {
    return (
        <dl className="grid grid-cols-1 gap-x-6 gap-y-3 rounded-xl border border-sidebar-border/70 p-4 text-sm sm:grid-cols-2 dark:border-sidebar-border">
            {facts.map(([label, value]) => (
                <div key={label}>
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="font-medium">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** Everything the install knows about one user (USR-002). */
export default function UserShow({
    user,
    organizations,
    groups,
    projects,
    skills,
    signInMethods,
    sshKeys,
    aiConnections,
    github,
    invitedBy,
    invitationsSent,
    impersonations,
    lifetimeUsage,
    usage,
    sessions,
}: {
    user: User;
    organizations: Organization[];
    groups: Group[];
    projects: Project[];
    skills: Skill[];
    signInMethods: SignInMethod[];
    sshKeys: SshKey[];
    aiConnections: AiConnection[];
    github: GitHub;
    invitedBy: InvitedBy | null;
    invitationsSent: InvitationSent[];
    impersonations: ImpersonationRecord[];
    lifetimeUsage: LifetimeUsage;
    usage: Usage;
    sessions: Session[];
}) {
    const { auth } = usePage().props;
    const isSelf = auth.user.id === user.id;
    const harnesses = (
        usage.agents.length > 0
            ? usage.agents.map((agent) => agent.harness)
            : (['claude_code', 'opencode', 'codex'] as AgentHarness[])
    ).map((harness) => ({
        key: harness,
        label: HARNESS_LABELS[harness],
        color: HARNESS_COLORS[harness],
    }));

    setLayoutProps({
        breadcrumbs: [
            { title: 'Users', href: index() },
            { title: user.name, href: '#' },
        ],
    });

    return (
        <>
            <Head title={user.name} />

            <div
                className="flex flex-1 flex-col gap-10"
                data-test="user-details"
            >
                <div className="flex items-center gap-4">
                    {user.avatar && (
                        <img
                            src={user.avatar}
                            alt=""
                            className="size-12 rounded-full"
                        />
                    )}
                    <div className="min-w-0 flex-1">
                        <Link
                            href={index()}
                            className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            ← Users
                        </Link>
                        <div className="flex items-center gap-2">
                            <h2 className="truncate text-xl font-semibold tracking-tight">
                                {user.name}
                            </h2>
                            {user.is_admin && <Badge>admin</Badge>}
                        </div>
                        <p className="truncate text-sm text-muted-foreground">
                            {user.email}
                        </p>
                    </div>
                    {!isSelf && (
                        <div className="flex flex-wrap gap-2">
                            {!user.is_admin && (
                                <ConfirmAction
                                    label="Impersonate"
                                    title={`Sign in as ${user.name}?`}
                                    description="You'll see the app as they do and can act as them, except changing how they sign in or deleting their account. It's recorded on this page. Stop from the banner at the bottom of the screen."
                                    href={ImpersonationController.store(
                                        user.id,
                                    )}
                                    testId="impersonate"
                                />
                            )}
                            <ConfirmAction
                                label="Sign out everywhere"
                                title={`Sign ${user.name} out everywhere?`}
                                description="Every browser they're signed in on is signed out, and remember-me stops working. They can sign in again."
                                href={UserController.signOut(user.id)}
                                testId="sign-out-everywhere"
                            />
                            {user.two_factor_enabled && (
                                <ConfirmAction
                                    label="Reset two-factor"
                                    title={`Turn off two-factor for ${user.name}?`}
                                    description="They'll sign in with just their password or provider until they set two-factor up again. Use this when they've lost their device."
                                    href={UserController.resetTwoFactor(
                                        user.id,
                                    )}
                                    testId="reset-two-factor"
                                />
                            )}
                        </div>
                    )}
                </div>

                <Section title="Account">
                    <Facts
                        facts={[
                            ['Joined', formatDate(user.created_at)],
                            [
                                'Last sign-in',
                                user.last_login_at
                                    ? `${formatDate(user.last_login_at)}${user.last_login_method ? ` · ${SIGN_IN_METHODS[user.last_login_method] ?? user.last_login_method}` : ''}`
                                    : 'Not recorded yet',
                            ],
                            [
                                'Invited by',
                                invitedBy
                                    ? `${invitedBy.name ?? 'A deleted user'} · ${invitedBy.organization} · ${formatDate(invitedBy.accepted_at)}`
                                    : 'Signed up without an invite',
                            ],
                            ['Last updated', formatDate(user.updated_at)],
                            [
                                'Email verified',
                                user.email_verified_at
                                    ? formatDate(user.email_verified_at)
                                    : 'Not verified',
                            ],
                            ['Password', user.has_password ? 'Set' : 'None'],
                            [
                                'Two-factor',
                                user.two_factor_enabled ? 'On' : 'Off',
                            ],
                            ['Passkeys', user.passkeys_count],
                        ]}
                    />
                </Section>

                <Section title="AI usage">
                    <Facts
                        facts={[
                            ['All-time cost', formatCost(lifetimeUsage.cost)],
                            [
                                'All-time tokens',
                                formatTokens(lifetimeUsage.tokens),
                            ],
                            ['Agent runs', lifetimeUsage.runs.toLocaleString()],
                            ['Last run', formatDate(lifetimeUsage.last_run_at)],
                            [
                                'Past 30 days',
                                `${formatCost(usage.totals.cost)} · ${formatTokens(usage.totals.tokens)} tokens`,
                            ],
                        ]}
                    />
                    <AreaLinesChart
                        title="Daily cost, past 30 days"
                        series={harnesses}
                        points={usage.series.map((point) => ({
                            t: point.t,
                            values: point.cost,
                        }))}
                        bucketSeconds={86400}
                        format={formatCost}
                        testId="user-usage-chart"
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        {(
                            [
                                ['By model, past 30 days', usage.models],
                                ['By project, past 30 days', usage.projects],
                            ] as const
                        ).map(([title, rows]) => (
                            <div key={title} className="space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    {title}
                                </p>
                                {rows.length === 0 ? (
                                    <Empty>No agent runs.</Empty>
                                ) : (
                                    <Rows>
                                        {rows.map((row, position) => (
                                            <Row
                                                key={`${row.name}-${position}`}
                                            >
                                                <span className="min-w-0 truncate font-medium">
                                                    {row.name}
                                                </span>
                                                <span className="text-muted-foreground tabular-nums">
                                                    {formatCost(row.cost)} ·{' '}
                                                    {formatTokens(row.tokens)}
                                                </span>
                                            </Row>
                                        ))}
                                    </Rows>
                                )}
                            </div>
                        ))}
                    </div>
                </Section>

                <Section title="Organizations" count={organizations.length}>
                    {organizations.length === 0 ? (
                        <Empty>Not in any organization.</Empty>
                    ) : (
                        <Rows>
                            {organizations.map((organization) => (
                                <Row key={organization.id}>
                                    <span className="font-medium">
                                        {organization.name}
                                        {organization.is_current && (
                                            <span className="ml-2 text-muted-foreground">
                                                (current)
                                            </span>
                                        )}
                                    </span>
                                    <Badge variant="secondary">
                                        {organization.role}
                                    </Badge>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="Groups" count={groups.length}>
                    {groups.length === 0 ? (
                        <Empty>Not in any group.</Empty>
                    ) : (
                        <Rows>
                            {groups.map((group) => (
                                <Row key={group.id}>
                                    <span>
                                        <span className="font-medium">
                                            {group.name}
                                        </span>
                                        <span className="ml-2 text-muted-foreground">
                                            {group.organization}
                                        </span>
                                    </span>
                                    <Badge variant="secondary">
                                        {group.role}
                                    </Badge>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="Projects" count={projects.length}>
                    {projects.length === 0 ? (
                        <Empty>No projects.</Empty>
                    ) : (
                        <Rows>
                            {projects.map((project) => (
                                <Row key={project.id}>
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {project.can_open ? (
                                                <Link
                                                    href={showProject(
                                                        project.id,
                                                    )}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {project.name}
                                                </Link>
                                            ) : (
                                                project.name
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {[
                                                project.organization,
                                                project.agent &&
                                                    `${project.agent}${project.model ? ` · ${project.model}` : ''}`,
                                                `${project.messages_count} messages`,
                                                `${project.tasks_count} tasks`,
                                                `updated ${formatDate(project.updated_at)}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                        {project.git_remote_url && (
                                            <p className="truncate text-muted-foreground">
                                                {project.git_remote_url}
                                            </p>
                                        )}
                                        {project.sandbox?.error && (
                                            <ProjectError
                                                label="Sandbox"
                                                message={project.sandbox.error}
                                            />
                                        )}
                                        {project.publish_error && (
                                            <ProjectError
                                                label="Publish"
                                                message={project.publish_error}
                                            />
                                        )}
                                        {project.git_sync_error && (
                                            <ProjectError
                                                label="Git sync"
                                                message={project.git_sync_error}
                                            />
                                        )}
                                        {project.published_url && (
                                            <a
                                                href={project.published_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-muted-foreground underline-offset-4 hover:underline"
                                            >
                                                {project.published_url}
                                            </a>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {project.archived && (
                                            <Badge variant="outline">
                                                archived
                                            </Badge>
                                        )}
                                        <Badge variant="secondary">
                                            {project.status}
                                        </Badge>
                                        {project.sandbox && (
                                            <Badge variant="outline">
                                                {project.sandbox.provider}:{' '}
                                                {project.sandbox.status}
                                            </Badge>
                                        )}
                                    </div>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="AI connections" count={aiConnections.length}>
                    {aiConnections.length === 0 ? (
                        <Empty>No AI connected.</Empty>
                    ) : (
                        <Rows>
                            {aiConnections.map((connection) => (
                                <Row key={connection.id}>
                                    <span>
                                        <span className="font-medium">
                                            {connection.provider}
                                        </span>
                                        <span className="ml-2 text-muted-foreground">
                                            {connection.type.replace('_', ' ')}
                                            {connection.hint &&
                                                ` · ${connection.hint}`}
                                        </span>
                                    </span>
                                    <span className="flex gap-2">
                                        {connection.is_default && (
                                            <Badge>default</Badge>
                                        )}
                                        <Badge variant="secondary">
                                            {connection.verified_at
                                                ? 'verified'
                                                : 'not verified'}
                                        </Badge>
                                    </span>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="Sign-in providers" count={signInMethods.length}>
                    {signInMethods.length === 0 ? (
                        <Empty>No providers connected.</Empty>
                    ) : (
                        <Rows>
                            {signInMethods.map((method) => (
                                <Row key={method.id}>
                                    <span>
                                        <span className="font-medium">
                                            {method.provider}
                                        </span>
                                        {method.email && (
                                            <span className="ml-2 text-muted-foreground">
                                                {method.email}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatDate(method.created_at)}
                                    </span>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="GitHub">
                    <Facts
                        facts={[
                            ['Signed in as', github.login ?? 'Not connected'],
                            [
                                'App installed on',
                                github.installations.length
                                    ? github.installations
                                          .map(
                                              (installation) =>
                                                  installation.account,
                                          )
                                          .join(', ')
                                    : '—',
                            ],
                        ]}
                    />
                </Section>

                <Section title="Invites sent" count={invitationsSent.length}>
                    {invitationsSent.length === 0 ? (
                        <Empty>No invites sent.</Empty>
                    ) : (
                        <Rows>
                            {invitationsSent.map((invitation) => (
                                <Row key={invitation.id}>
                                    <span>
                                        <span className="font-medium">
                                            {invitation.accepted_by ??
                                                invitation.email ??
                                                'Anyone with the link'}
                                        </span>
                                        <span className="ml-2 text-muted-foreground">
                                            {invitation.organization} ·{' '}
                                            {formatDate(invitation.created_at)}
                                        </span>
                                    </span>
                                    <Badge variant="secondary">
                                        {invitation.status}
                                    </Badge>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="Agent skills" count={skills.length}>
                    {skills.length === 0 ? (
                        <Empty>No skills.</Empty>
                    ) : (
                        <Rows>
                            {skills.map((skill) => (
                                <Row key={skill.id}>
                                    <span>
                                        <span className="font-medium">
                                            {skill.name}
                                        </span>
                                        <span className="ml-2 text-muted-foreground">
                                            {skill.organization}
                                        </span>
                                    </span>
                                    {skill.shared && (
                                        <Badge variant="secondary">
                                            shared
                                        </Badge>
                                    )}
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="SSH keys" count={sshKeys.length}>
                    {sshKeys.length === 0 ? (
                        <Empty>No SSH keys.</Empty>
                    ) : (
                        <Rows>
                            {sshKeys.map((key) => (
                                <Row key={key.id}>
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {key.name}
                                        </p>
                                        <p className="truncate font-mono text-xs text-muted-foreground">
                                            {key.fingerprint}
                                        </p>
                                    </div>
                                    <span className="text-muted-foreground">
                                        {formatDate(key.created_at)}
                                    </span>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="Impersonations" count={impersonations.length}>
                    {impersonations.length === 0 ? (
                        <Empty>No admin has signed in as them.</Empty>
                    ) : (
                        <Rows>
                            {impersonations.map((impersonation) => (
                                <Row key={impersonation.id}>
                                    <span>
                                        <span className="font-medium">
                                            {impersonation.admin ??
                                                'A deleted admin'}
                                        </span>
                                        {impersonation.ip_address && (
                                            <span className="ml-2 text-muted-foreground">
                                                {impersonation.ip_address}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatDate(impersonation.started_at)} →{' '}
                                        {impersonation.ended_at
                                            ? formatDate(impersonation.ended_at)
                                            : 'not stopped'}
                                    </span>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>

                <Section title="Recent sessions" count={sessions.length}>
                    {sessions.length === 0 ? (
                        <Empty>No signed-in browsers.</Empty>
                    ) : (
                        <Rows>
                            {sessions.map((session, position) => (
                                <Row key={position}>
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {session.ip_address ?? 'Unknown IP'}
                                        </p>
                                        <p className="truncate text-muted-foreground">
                                            {session.user_agent}
                                        </p>
                                    </div>
                                    <span className="text-muted-foreground">
                                        {formatDate(session.last_active_at)}
                                    </span>
                                </Row>
                            ))}
                        </Rows>
                    )}
                </Section>
            </div>
        </>
    );
}
