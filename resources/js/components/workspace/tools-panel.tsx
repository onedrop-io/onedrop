import {
    Activity,
    CodeXml,
    Database,
    Globe,
    GitBranch,
    HardDrive,
    Image as ImageIcon,
    KeyRound,
    TrendingUp,
    Plug,
    Rocket,
    ShieldCheck,
    Sparkles,
    ToggleRight,
    UserCog,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import AuthPanel from '@/components/workspace/auth-panel';
import DatabasePanel from '@/components/workspace/database-panel';
import DeveloperPanel from '@/components/workspace/developer-panel';
import FlagsPanel from '@/components/workspace/flags-panel';
import GitPanel from '@/components/workspace/git-panel';
import GrowthPanel from '@/components/workspace/growth-panel';
import IconPanel from '@/components/workspace/icon-panel';
import MonitoringPanel from '@/components/workspace/monitoring-panel';
import SecretsPanel from '@/components/workspace/secrets-panel';
import SkillsPanel from '@/components/workspace/skills-panel';
import StoragePanel from '@/components/workspace/storage-panel';
import { cn } from '@/lib/utils';
import type { Publication } from '@/types';

type Section = {
    id: string;
    label: string;
    icon: LucideIcon;
    description: string;
};

/** Grouped like Replit's Tools menu. */
const GROUPS: { label: string; sections: Section[] }[] = [
    {
        label: 'Cloud',
        sections: [
            {
                id: 'publishing',
                label: 'Publishing',
                icon: Rocket,
                description:
                    'Share your app at its own URL, privately with your team or publicly.',
            },
            {
                id: 'domains',
                label: 'Domains',
                icon: Globe,
                description: 'Use your own domain name for the published app.',
            },
            {
                id: 'monitoring',
                label: 'Monitoring',
                icon: Activity,
                description:
                    'Requests, errors, response times and resource use for your app.',
            },
            {
                id: 'database',
                label: 'Database',
                icon: Database,
                description:
                    "Browse and edit your app's data, or run SQL against it.",
            },
            {
                id: 'auth',
                label: 'Users & Auth',
                icon: UserCog,
                description:
                    'Let people sign up and sign in to your app, and see who has.',
            },
            {
                id: 'growth',
                label: 'Growth',
                icon: TrendingUp,
                description:
                    'Review opportunities to grow your app and acquire new users.',
            },
            {
                id: 'flags',
                label: 'Feature Flags',
                icon: ToggleRight,
                description:
                    'Turn parts of your app on or off without changing its code.',
            },
            {
                id: 'security',
                label: 'Security',
                icon: ShieldCheck,
                description: 'Check your app for common security problems.',
            },
            {
                id: 'storage',
                label: 'App Storage',
                icon: HardDrive,
                description:
                    'Host and save uploads like images, videos, and documents.',
            },
        ],
    },
    {
        label: 'Setup',
        sections: [
            {
                id: 'icon',
                label: 'App Icon',
                icon: ImageIcon,
                description:
                    'The icon in browser tabs and bookmarks, and on the project in your sidebar.',
            },
            {
                id: 'secrets',
                label: 'Secrets',
                icon: KeyRound,
                description:
                    'API keys and passwords your app uses, kept out of the code.',
            },
            {
                id: 'integrations',
                label: 'Integrations',
                icon: Plug,
                description: 'Connect services like email, payments and Slack.',
            },
            {
                id: 'git',
                label: 'Git',
                icon: GitBranch,
                description:
                    "Your app's changes and history, and pushing them to GitHub or another git host.",
            },
            {
                id: 'skills',
                label: 'Agent Skills',
                icon: Sparkles,
                description: 'Teach the agent how your team likes things done.',
            },
            {
                id: 'developer',
                label: 'Developer',
                icon: CodeXml,
                description:
                    "Networking, resources and SSH access for your app's sandbox.",
            },
        ],
    },
];

const SECTIONS = GROUPS.flatMap((group) => group.sections);

export default function ToolsPanel({
    projectId,
    running,
    working,
    publication,
    initialSection,
    onSectionChange,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
    publication: Publication;
    /** The section to open first, e.g. "git" (from `?tool=git`). */
    initialSection?: string | null;
    /** The user opened another section (the workspace keeps it in the URL). */
    onSectionChange?: (section: string) => void;
}) {
    const [active, setActive] = useState(() =>
        SECTIONS.some((s) => s.id === initialSection)
            ? (initialSection as string)
            : 'publishing',
    );
    const section = SECTIONS.find((s) => s.id === active) ?? SECTIONS[0];

    // Asked for another section from outside (e.g. the header's git menu).
    useEffect(() => {
        if (SECTIONS.some((s) => s.id === initialSection)) {
            setActive(initialSection as string);
        }
    }, [initialSection]);

    return (
        <div className="flex min-h-0 flex-1" data-test="tools-panel">
            <nav
                aria-label="Tools"
                className="w-52 shrink-0 overflow-y-auto border-r border-sidebar-border/70 p-2 dark:border-sidebar-border"
            >
                {GROUPS.map((group) => (
                    <div key={group.label} className="mb-3">
                        <p className="px-2 py-1 text-xs text-muted-foreground">
                            {group.label}
                        </p>
                        <ul>
                            {group.sections.map(({ id, label, icon: Icon }) => (
                                <li key={id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setActive(id);
                                            onSectionChange?.(id);
                                        }}
                                        aria-current={
                                            active === id ? 'page' : undefined
                                        }
                                        data-test={`tool-${id}`}
                                        className={cn(
                                            'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted',
                                            active === id &&
                                                'bg-muted font-medium',
                                        )}
                                    >
                                        <Icon className="size-4 text-muted-foreground" />
                                        {label}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </nav>

            <section
                className="flex-1 overflow-y-auto p-6"
                data-test={`tool-page-${section.id}`}
            >
                <h2 className="flex items-center gap-2 text-lg font-medium">
                    <section.icon className="size-5 text-muted-foreground" />
                    {section.label}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {section.description}
                </p>

                <div className="mt-6">
                    {section.id === 'publishing' ? (
                        <PublishingOverview publication={publication} />
                    ) : section.id === 'monitoring' ? (
                        <MonitoringPanel
                            projectId={projectId}
                            running={running}
                        />
                    ) : section.id === 'auth' ? (
                        <AuthPanel
                            projectId={projectId}
                            running={running}
                            working={working}
                        />
                    ) : section.id === 'growth' ? (
                        <GrowthPanel
                            projectId={projectId}
                            running={running}
                            working={working}
                        />
                    ) : section.id === 'secrets' ? (
                        <SecretsPanel
                            projectId={projectId}
                            running={running}
                            working={working}
                        />
                    ) : section.id === 'flags' ? (
                        <FlagsPanel
                            projectId={projectId}
                            running={running}
                            working={working}
                        />
                    ) : section.id === 'storage' ? (
                        <StoragePanel
                            projectId={projectId}
                            running={running}
                            working={working}
                        />
                    ) : section.id === 'git' ? (
                        <GitPanel
                            projectId={projectId}
                            running={running}
                            working={working}
                        />
                    ) : section.id === 'icon' ? (
                        <IconPanel projectId={projectId} running={running} />
                    ) : section.id === 'developer' ? (
                        <DeveloperPanel
                            projectId={projectId}
                            running={running}
                        />
                    ) : section.id === 'skills' ? (
                        <SkillsPanel
                            projectId={projectId}
                            running={running}
                            working={working}
                        />
                    ) : section.id === 'database' ? (
                        <DatabasePanel
                            projectId={projectId}
                            running={running}
                        />
                    ) : (
                        <ComingSoon />
                    )}
                </div>
            </section>
        </div>
    );
}

function PublishingOverview({ publication }: { publication: Publication }) {
    const live = publication.status === 'live';

    const status = live
        ? 'Live'
        : publication.status === 'publishing'
          ? 'Publishing…'
          : publication.status === 'failed'
            ? 'Failed'
            : 'Not published';

    return (
        <div className="max-w-xl space-y-4">
            <dl className="grid grid-cols-[8rem_1fr] gap-y-3 rounded-xl border border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border">
                <dt className="text-muted-foreground">Status</dt>
                <dd
                    className="flex items-center gap-2"
                    data-test="tools-publish-status"
                >
                    <span
                        className={cn(
                            'size-2 rounded-full',
                            live ? 'bg-green-500' : 'bg-muted-foreground/40',
                        )}
                    />
                    {status}
                </dd>
                {live && publication.visibility && (
                    <>
                        <dt className="text-muted-foreground">
                            Who can open it
                        </dt>
                        <dd>{publication.audience}</dd>
                    </>
                )}
                {live && publication.url && (
                    <>
                        <dt className="text-muted-foreground">URL</dt>
                        <dd className="truncate">
                            <a
                                href={publication.url}
                                target="_blank"
                                rel="noreferrer"
                                className="underline underline-offset-4"
                            >
                                {publication.url.replace('https://', '')}
                            </a>
                        </dd>
                    </>
                )}
                {publication.published_by && publication.published_at && (
                    <>
                        <dt className="text-muted-foreground">Published by</dt>
                        <dd>{publication.published_by}</dd>
                    </>
                )}
            </dl>
            <p className="text-sm text-muted-foreground">
                Use the Publish button at the top right to publish, change who
                can open it, or unpublish.
            </p>
        </div>
    );
}

function ComingSoon() {
    return (
        <div
            className="max-w-xl rounded-xl border border-dashed border-sidebar-border p-6 text-sm text-muted-foreground"
            data-test="tool-coming-soon"
        >
            Coming soon.
        </div>
    );
}
