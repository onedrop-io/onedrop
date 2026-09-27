import {
    Activity,
    Database,
    Globe,
    GitBranch,
    HardDrive,
    KeyRound,
    Plug,
    Rocket,
    ShieldCheck,
    Sparkles,
    UserCog,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import type { Publication } from '@/types';

type Section = {
    id: string;
    label: string;
    icon: LucideIcon;
    description: string;
};

/** Grouped like Replit's Tools menu. Only Publishing has real content so far. */
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
                    'See visits, errors and performance of the published app.',
            },
            {
                id: 'database',
                label: 'Database',
                icon: Database,
                description: "Browse and edit your app's data.",
            },
            {
                id: 'auth',
                label: 'Users & Auth',
                icon: UserCog,
                description: 'Manage who can sign in to your app.',
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
                description: 'Files and images your app stores.',
            },
        ],
    },
    {
        label: 'Setup',
        sections: [
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
                    "Your app's change history and connection to GitHub.",
            },
            {
                id: 'skills',
                label: 'Agent Skills',
                icon: Sparkles,
                description: 'Teach the agent how your team likes things done.',
            },
        ],
    },
];

const SECTIONS = GROUPS.flatMap((group) => group.sections);

export default function ToolsPanel({
    publication,
}: {
    publication: Publication;
}) {
    const [active, setActive] = useState('publishing');
    const section = SECTIONS.find((s) => s.id === active) ?? SECTIONS[0];

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
                                        onClick={() => setActive(id)}
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
                        <dd>
                            {publication.visibility === 'public'
                                ? 'Anyone with the URL'
                                : "People on your team's tailnet"}
                        </dd>
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
