import { ArrowRight, BookOpen, Laptop, Globe, Server } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useRef, useState } from 'react';
import { GitHubMark } from '@/components/home/github-mark';
import { InstallCommand } from '@/components/home/install-command';
import {
    INSTALL_COMMAND,
    INSTALL_DOCS_URL,
    INSTALL_DOMAIN_COMMAND,
    INSTALL_SERVER_COMMAND,
    REPOSITORY_URL,
} from '@/lib/links';

const TARGETS = [
    {
        id: 'laptop',
        label: 'Your laptop',
        icon: Laptop,
        command: INSTALL_COMMAND,
        note: 'Starts OneDrop at http://localhost:8000 and opens the sign-up page in your browser.',
    },
    {
        id: 'server',
        label: 'A server',
        icon: Server,
        command: INSTALL_SERVER_COMMAND,
        note: 'Serves OneDrop over HTTPS at <your server’s IP>.sslip.io, with no DNS to set up. Open ports 80 and 443 first. It prints a one-time link to create the admin account.',
    },
    {
        id: 'domain',
        label: 'Your domain',
        icon: Globe,
        command: INSTALL_DOMAIN_COMMAND,
        note: 'Point onedrop.example.com and *.onedrop.example.com at the server, open ports 80 and 443, then run it. Certificates come from Let’s Encrypt.',
    },
];

const INSTALL_STEPS = [
    {
        title: 'Run the command',
        body: 'It checks for Docker (and installs it on Linux), adds the drop command, and starts OneDrop.',
    },
    {
        title: 'Create your account',
        body: 'The first account is the admin. Invite your team from Settings whenever you’re ready.',
    },
    {
        title: 'Connect your AI and build',
        body: 'Use your Claude or ChatGPT subscription, or paste a key from Anthropic, OpenAI, Google, OpenRouter, or Ollama. Then describe your first app.',
    },
];

const REQUIREMENTS = [
    'macOS or Linux',
    'Docker',
    '5 GB of free disk',
    'An AI plan or API key',
];

/** The home page's install section: one command per target (laptop, server, own domain), the steps after it, and links to the guide and the source. */
export function InstallSection() {
    const [selectedId, setSelectedId] = useState(TARGETS[0].id);
    const tabRefs = useRef<Array<HTMLButtonElement | null>>([]);
    const selected =
        TARGETS.find((target) => target.id === selectedId) ?? TARGETS[0];

    const moveFocus = (event: KeyboardEvent, index: number) => {
        const step =
            event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

        if (step === 0) {
            return;
        }

        event.preventDefault();
        const next = (index + step + TARGETS.length) % TARGETS.length;
        setSelectedId(TARGETS[next].id);
        tabRefs.current[next]?.focus();
    };

    return (
        <section
            id="install"
            className="scroll-mt-16 border-b border-[#2A2320]"
        >
            <div className="mx-auto grid max-w-6xl gap-14 px-6 py-24 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                <div>
                    <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                        Self-host it in one command
                    </h2>
                    <p className="mt-4 text-lg leading-relaxed text-[#B3A69C]">
                        Free and source available, on your laptop or your own
                        server. Docker is the only thing it needs.
                    </p>
                    <ol className="mt-10 space-y-7">
                        {INSTALL_STEPS.map((step, index) => (
                            <li key={step.title} className="flex gap-4">
                                <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[#FF4D1C]/15 font-display text-sm font-bold text-[#FF9A5C] ring-1 ring-[#FF4D1C]/40">
                                    {index + 1}
                                </span>
                                <div>
                                    <h3 className="font-display font-bold">
                                        {step.title}
                                    </h3>
                                    <p className="mt-1 leading-relaxed text-[#B3A69C]">
                                        {step.body}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </div>

                {/* min-w-0: the command wraps inside its box instead of widening the page. */}
                <div className="min-w-0 self-center rounded-2xl bg-[#151110] p-2 ring-1 ring-[#2A2320]">
                    <div
                        role="tablist"
                        aria-label="Where to install"
                        className="flex gap-1 overflow-x-auto"
                    >
                        {TARGETS.map((target, index) => {
                            const isSelected = target.id === selected.id;

                            return (
                                <button
                                    key={target.id}
                                    ref={(element) => {
                                        tabRefs.current[index] = element;
                                    }}
                                    type="button"
                                    role="tab"
                                    id={`install-tab-${target.id}`}
                                    aria-selected={isSelected}
                                    aria-controls="install-panel"
                                    tabIndex={isSelected ? 0 : -1}
                                    onClick={() => setSelectedId(target.id)}
                                    onKeyDown={(event) =>
                                        moveFocus(event, index)
                                    }
                                    data-test={`install-tab-${target.id}`}
                                    className={`flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none ${isSelected ? 'bg-[#2A2320] text-white' : 'text-[#B3A69C] hover:text-white'}`}
                                >
                                    <target.icon
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {target.label}
                                </button>
                            );
                        })}
                    </div>

                    <div
                        id="install-panel"
                        role="tabpanel"
                        aria-labelledby={`install-tab-${selected.id}`}
                        className="p-3 pt-4"
                    >
                        <InstallCommand command={selected.command} />
                        <p className="mt-3 min-h-[3rem] text-sm leading-relaxed text-[#B3A69C]">
                            {selected.note}
                        </p>

                        <ul className="mt-5 flex flex-wrap gap-2 border-t border-[#2A2320] pt-5 text-xs text-[#B3A69C]">
                            {REQUIREMENTS.map((requirement) => (
                                <li
                                    key={requirement}
                                    className="rounded-full bg-white/[0.04] px-3 py-1 ring-1 ring-white/10"
                                >
                                    {requirement}
                                </li>
                            ))}
                        </ul>
                        <p className="mt-4 text-sm text-[#7D7068]">
                            Update any time with{' '}
                            <code className="font-mono text-[#F5EFEA]">
                                drop update
                            </code>
                            . Your projects and data are kept.
                        </p>

                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={INSTALL_DOCS_URL}
                                className="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-[#F5EFEA] ring-1 ring-[#3A302B] hover:bg-white/[0.04] focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
                            >
                                <BookOpen
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Install guide
                                <ArrowRight
                                    aria-hidden="true"
                                    className="size-3.5 text-[#7D7068]"
                                />
                            </a>
                            <a
                                href={REPOSITORY_URL}
                                className="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-[#F5EFEA] ring-1 ring-[#3A302B] hover:bg-white/[0.04] focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
                            >
                                <GitHubMark className="size-4" />
                                View the source on GitHub
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
