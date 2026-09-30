import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowUp,
    Check,
    Loader2,
    Lock,
    MousePointer2,
    Pause,
    Play,
    Plus,
    Send,
    Server,
} from 'lucide-react';
import type { RefObject } from 'react';
import { useEffect, useRef, useState } from 'react';
import { BlackHole } from '@/components/home/black-hole';
import { Galaxy } from '@/components/home/galaxy';
import { CosmicZoomControl } from '@/components/home/cosmic-zoom-control';
import {
    DROP_START_MS,
    GravityField,
    IMPACT_DELAY_MS,
    ParticleUniverse,
} from '@/components/home/particle-universe';
import { DemoVideo } from '@/components/home/demo-video';
import { DropMark } from '@/components/home/drop-mark';
import { FEATURES } from '@/components/home/features';
import { InstallCommand } from '@/components/home/install-command';
import { SiteFooter } from '@/components/home/site-footer';
import { SiteHeader } from '@/components/home/site-header';
import { SparklesText } from '@/components/home/sparkles-text';
import { dashboard, register } from '@/routes';

const BRAND = 'OneDrop';

const DEMO_PROMPT = 'A time-off tracker for my team';
const TYPE_SPEED_MS = 45;
const TYPED_AT_MS = DEMO_PROMPT.length * TYPE_SPEED_MS;
const SENT_AT_MS = TYPED_AT_MS + 350;
const DEMO_STEPS = [
    { label: 'Setting up a database for time-off requests', at: 2600 },
    { label: 'Building the request form', at: 3800 },
    { label: 'Adding a who’s-out calendar', at: 5000 },
    { label: 'Running the tests: 12 passed', at: 6200 },
];
const REPLY_AT_MS = 7300;
const CURSOR_AT_MS = 8300;
const PUBLISH_AT_MS = 9100;
const PUBLISHED_AT_MS = 10100;
const DEMO_END_MS = 11000;
const DEMO_LOOP_MS = 16000;
const DEMO_URL = 'timeoff.yourteam.ts.net';

const INTEGRATIONS = [
    {
        role: 'Your AI',
        tools: [
            {
                name: 'Claude',
                logo: '/images/logos/claude.svg',
                url: 'https://claude.com',
            },
            {
                name: 'OpenAI',
                logo: '/images/logos/openai.svg',
                url: 'https://openai.com',
            },
            {
                name: 'Gemini',
                logo: '/images/logos/gemini.svg',
                url: 'https://ai.google.dev',
            },
            {
                name: 'OpenRouter',
                logo: '/images/logos/openrouter.svg',
                url: 'https://openrouter.ai',
            },
        ],
    },
    {
        role: 'The builder',
        tools: [
            {
                name: 'OpenCode',
                logo: '/images/logos/opencode.svg',
                url: 'https://opencode.ai',
            },
        ],
    },
    {
        role: 'Sandboxes',
        tools: [
            {
                name: 'Docker',
                logo: '/images/logos/docker.svg',
                url: 'https://www.docker.com',
            },
            {
                name: 'Blaxel',
                logo: '/images/logos/blaxel.svg',
                url: 'https://blaxel.ai',
            },
            {
                name: 'Runtime',
                logo: '/images/logos/runtime.svg',
                url: 'https://withruntime.com',
            },
            {
                name: 'E2B',
                logo: '/images/logos/e2b.png',
                url: 'https://e2b.dev',
            },
            {
                name: 'Daytona',
                logo: '/images/logos/daytona.svg',
                url: 'https://www.daytona.io',
            },
            {
                name: 'Vercel',
                logo: '/images/logos/vercel.svg',
                url: 'https://vercel.com',
            },
        ],
    },
    {
        role: 'Instant links',
        tools: [
            {
                name: 'Tailscale',
                logo: '/images/logos/tailscale.svg',
                url: 'https://tailscale.com',
            },
        ],
    },
    {
        role: 'Your laptop',
        tools: [
            {
                name: 'macOS',
                logo: '/images/logos/apple.svg',
                url: 'https://www.apple.com/macos/',
            },
            {
                name: 'Linux',
                logo: '/images/logos/linux.svg',
                url: 'https://www.kernel.org',
            },
        ],
    },
    {
        role: 'Your servers',
        tools: [
            {
                name: 'AWS',
                logo: '/images/logos/aws.svg',
                url: 'https://aws.amazon.com',
            },
            {
                name: 'Google Cloud',
                logo: '/images/logos/googlecloud.svg',
                url: 'https://cloud.google.com',
            },
            {
                name: 'Hetzner',
                logo: '/images/logos/hetzner.svg',
                url: 'https://www.hetzner.com',
            },
            {
                name: 'DigitalOcean',
                logo: '/images/logos/digitalocean.svg',
                url: 'https://www.digitalocean.com',
            },
            {
                name: 'Vultr',
                logo: '/images/logos/vultr.svg',
                url: 'https://www.vultr.com',
            },
            {
                name: 'Ubuntu',
                logo: '/images/logos/ubuntu.svg',
                url: 'https://ubuntu.com',
            },
        ],
    },
];

const STEPS = [
    {
        title: 'Connect your AI',
        body: 'Sign in with your ChatGPT Plus or Pro plan, or paste a key from Anthropic, OpenAI, Google, or OpenRouter. It takes a minute, and you only do it once.',
    },
    {
        title: 'Say what you need',
        body: '“A booking form for the meeting rooms.” “A tracker for our client renewals.” Plain English is all it takes.',
    },
    {
        title: 'Watch it get built',
        body: 'The AI writes the code and runs the tests while you watch. The real app runs next to the chat, so you can click around and ask for changes.',
    },
    {
        title: 'Share it',
        body: 'Click Publish for an instant link on your team’s private Tailscale network. Flip it public when you’re ready.',
    },
];

const COMPARISON = [
    {
        topic: 'Where your apps and data live',
        us: 'Your laptop or your servers',
        them: 'Their cloud',
    },
    {
        topic: 'How you pay for AI',
        us: 'Your own plan or key, at cost',
        them: 'Their credits, with a markup',
    },
    {
        topic: 'What you can build',
        us: 'Real code, any framework',
        them: 'Usually one stack',
    },
    {
        topic: 'What it costs',
        us: 'Free to self-host',
        them: 'Another monthly plan',
    },
    {
        topic: 'Leaving',
        us: 'Keep everything. It’s your code.',
        them: 'Export and hope',
    },
];

const FAQS = [
    {
        question: 'Do I need to know how to code?',
        answer: 'No. If you can describe what you want to a coworker, you can build it here. Developers can still open the files and terminal whenever they like.',
    },
    {
        question: 'Which AI does it use?',
        answer: 'Yours. Sign in with your ChatGPT Plus or Pro plan, paste an Anthropic, OpenAI, or Gemini API key, or sign in with OpenRouter for hundreds of other models. You pay your provider directly, with no markup.',
    },
    {
        question: 'Is it really ready for production?',
        answer: `${BRAND} writes standard code (React, or Laravel when the app needs accounts and a database) and tests it as it goes. Sign-in, secrets, analytics, and feature flags are built in, and every app runs on infrastructure you control. It’s the same code a developer would write, so your team can review it, extend it, and ship it.`,
    },
    {
        question: 'Where do my apps run?',
        answer: `Wherever you run ${BRAND}: your own computer, or a server you control. One script installs it on any Ubuntu server, and there’s a ready-made setup for AWS.`,
    },
    {
        question: 'Who can see what I build?',
        answer: 'Only you, until you share. Publishing gives the app a Tailscale link that only people on your team’s network can open. Switch it to public when you want anyone with the link to get in.',
    },
    {
        question: 'What does it cost?',
        answer: `Self-hosting ${BRAND} is free: the code is source available, so you only pay your AI provider directly and for your own servers. Want us to host it? Cloud plans start at $16 a month, with no markup on your own AI and credits included if you don’t have any. See the pricing page for details.`,
    },
    {
        question: 'What if the AI gets something wrong?',
        answer: 'Tell it, the same way you’d tell a person. “The total is off by one day” is enough.',
    },
];

function usePrefersReducedMotion(): boolean {
    const [prefersReducedMotion, setPrefersReducedMotion] = useState(false);

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        setPrefersReducedMotion(query.matches);

        const onChange = (event: MediaQueryListEvent) =>
            setPrefersReducedMotion(event.matches);
        query.addEventListener('change', onChange);

        return () => query.removeEventListener('change', onChange);
    }, []);

    return prefersReducedMotion;
}

function useDemoClock(isRunning: boolean): number {
    const [elapsed, setElapsed] = useState(-DROP_START_MS);

    useEffect(() => {
        if (!isRunning) {
            return;
        }

        let lastTickAt = performance.now();

        const interval = window.setInterval(() => {
            const now = performance.now();
            const delta = Math.min(now - lastTickAt, 250);
            lastTickAt = now;
            setElapsed((current) =>
                current + delta < 0
                    ? current + delta
                    : (current + delta) % DEMO_LOOP_MS,
            );
        }, 50);

        return () => window.clearInterval(interval);
    }, [isRunning]);

    return elapsed;
}

function BuildDemo() {
    const prefersReducedMotion = usePrefersReducedMotion();
    const [isPaused, setIsPaused] = useState(false);
    const clock = useDemoClock(!prefersReducedMotion && !isPaused);
    const elapsed = prefersReducedMotion ? DEMO_END_MS : Math.max(0, clock);

    const typedPrompt = DEMO_PROMPT.slice(
        0,
        Math.floor(elapsed / TYPE_SPEED_MS),
    );
    const isSent = elapsed >= SENT_AT_MS;
    const visibleSteps = DEMO_STEPS.filter((step) => elapsed >= step.at);
    const hasReplied = elapsed >= REPLY_AT_MS;
    const showsApp = elapsed >= DEMO_STEPS[1].at + 600;
    const showsReport = elapsed >= DEMO_STEPS[2].at + 600;
    const showsCursor = elapsed >= CURSOR_AT_MS;
    const isPublishing = elapsed >= PUBLISH_AT_MS && elapsed < PUBLISHED_AT_MS;
    const isPublished = elapsed >= PUBLISHED_AT_MS;
    const isApproved = elapsed >= REPLY_AT_MS + 500;

    return (
        <div className="relative">
            <p className="sr-only">
                Demo: someone types “{DEMO_PROMPT}” into the chat. {BRAND} sets
                up a database, builds a request form, adds a who’s-out calendar,
                and runs the tests. The working time-off tracker appears in the
                preview next to the chat. One click on Publish gives it a
                private Tailscale link, {DEMO_URL}, that the whole team can
                open.
            </p>
            <div
                aria-hidden="true"
                className="relative overflow-hidden rounded-2xl bg-[#151110] shadow-[0_50px_120px_-40px_rgba(255,77,28,0.55)] ring-1 ring-[#3A302B]"
            >
                <div className="pointer-events-none absolute inset-x-0 top-0 z-20 h-px bg-gradient-to-r from-transparent via-[#FFB27A] to-transparent" />
                <div className="flex items-center gap-2 border-b border-[#2A2320] bg-[#100D0B] px-4 py-3">
                    <span className="size-2.5 rounded-full bg-[#3A302B]" />
                    <span className="size-2.5 rounded-full bg-[#3A302B]" />
                    <span className="size-2.5 rounded-full bg-[#3A302B]" />
                    <div className="mx-auto flex items-center gap-2 rounded-md bg-[#151110] px-3 py-1 text-xs text-[#B3A69C] ring-1 ring-[#2A2320]">
                        <DropMark className="size-3 text-[#FF9A5C]" />
                        Team time off
                    </div>
                    <div className="relative">
                        <span
                            className={`flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-semibold text-white transition-colors ${isPublishing ? 'bg-[#E8341C]' : 'bg-[#FF4D1C]'}`}
                        >
                            {isPublishing && (
                                <Loader2 className="size-3 animate-spin" />
                            )}
                            {isPublished ? 'Republish' : 'Publish'}
                        </span>
                        <MousePointer2
                            className={`absolute top-3 left-8 size-5 fill-white text-[#0A0807] drop-shadow transition-all duration-700 ease-out ${showsCursor && !isPublished ? 'translate-x-0 translate-y-0 opacity-100' : '-translate-x-24 translate-y-24 opacity-0'} ${isPublishing ? 'scale-90' : ''}`}
                        />
                        {isPublished && (
                            <div className="absolute top-full right-0 z-10 mt-2 w-72 animate-in rounded-xl bg-[#151110] p-4 text-left shadow-[0_20px_40px_-12px_rgba(0,0,0,0.6)] ring-1 ring-[#3A302B] duration-300 fade-in slide-in-from-top-1">
                                <p className="flex items-center gap-2 text-sm font-semibold text-[#F5EFEA]">
                                    <img
                                        src="/images/logos/tailscale.svg"
                                        alt=""
                                        className="size-3.5"
                                    />
                                    Published to your tailnet
                                </p>
                                <div className="mt-3 flex items-center gap-2 rounded-lg bg-[#100D0B] px-3 py-2 text-xs ring-1 ring-[#2A2320]">
                                    <span className="flex-1 truncate font-medium text-[#F5EFEA]">
                                        https://{DEMO_URL}
                                    </span>
                                    <span className="text-[#FF9A5C]">Copy</span>
                                </div>
                                <p className="mt-2.5 flex items-center gap-1.5 text-xs text-[#B3A69C]">
                                    <Lock className="size-3" />
                                    Only people on your team can open it
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                <div className="grid md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                    <div className="flex min-h-[380px] flex-col border-b border-[#2A2320] p-5 md:border-r md:border-b-0">
                        <div className="flex-1 space-y-4 text-sm">
                            {isSent && (
                                <div className="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-[#FF4D1C] px-4 py-2.5 text-white">
                                    {DEMO_PROMPT}
                                </div>
                            )}

                            {isSent && (
                                <ul className="space-y-2">
                                    {visibleSteps.map((step, index) => {
                                        const isDone =
                                            index < visibleSteps.length - 1 ||
                                            hasReplied;

                                        return (
                                            <li
                                                key={step.label}
                                                className="flex items-center gap-2.5 text-[#B3A69C]"
                                            >
                                                {isDone ? (
                                                    <span className="grid size-5 place-items-center rounded-full bg-[#FF4D1C]/20 text-[#FF9A5C]">
                                                        <Check className="size-3" />
                                                    </span>
                                                ) : (
                                                    <Loader2 className="size-5 animate-spin text-[#FF9A5C]" />
                                                )}
                                                {step.label}
                                            </li>
                                        );
                                    })}
                                    {visibleSteps.length === 0 && (
                                        <li className="flex items-center gap-2.5 text-[#B3A69C]">
                                            <Loader2 className="size-5 animate-spin text-[#FF9A5C]" />
                                            Planning your app
                                        </li>
                                    )}
                                </ul>
                            )}

                            {hasReplied && (
                                <div className="w-fit max-w-[90%] rounded-2xl rounded-bl-md bg-[#221C18] px-4 py-2.5 text-[#F5EFEA]">
                                    Done. Your time-off tracker is running on
                                    the right. Try approving a request.
                                </div>
                            )}
                        </div>

                        <div className="mt-5 flex items-center gap-2 rounded-xl border border-[#3A302B] px-3 py-2.5 text-sm">
                            <span
                                className={
                                    isSent || typedPrompt.length === 0
                                        ? 'flex-1 text-[#7D7068]'
                                        : 'flex-1 text-[#F5EFEA]'
                                }
                            >
                                {isSent || typedPrompt.length === 0 ? (
                                    'Ask for a change…'
                                ) : (
                                    <>
                                        {typedPrompt}
                                        <span className="ml-px inline-block h-4 w-px translate-y-0.5 animate-pulse bg-[#F5EFEA]" />
                                    </>
                                )}
                            </span>
                            <span className="grid size-7 place-items-center rounded-lg bg-[#FF4D1C] text-white">
                                <Send className="size-3.5" />
                            </span>
                        </div>
                    </div>

                    <div className="flex min-h-[380px] flex-col bg-[#100D0B]">
                        <div className="flex items-center gap-2 border-b border-[#2A2320] px-4 py-2 text-xs">
                            <span className="rounded-md bg-[#151110] px-2.5 py-1 font-medium text-[#F5EFEA] ring-1 ring-[#2A2320]">
                                Preview
                            </span>
                            <span
                                className={`truncate text-[#B3A69C] transition-opacity duration-500 ${isPublished ? 'opacity-100' : 'opacity-0'}`}
                            >
                                {DEMO_URL}
                            </span>
                            <span
                                className={`ml-auto flex items-center gap-1.5 font-medium transition-opacity duration-500 ${isPublished ? 'text-[#3DDC97] opacity-100' : 'opacity-0'}`}
                            >
                                <span className="size-1.5 rounded-full bg-[#12B76A]" />
                                Live
                            </span>
                        </div>

                        <div className="relative flex-1 p-5">
                            {!showsApp ? (
                                <div className="grid h-full place-items-center text-center text-sm text-[#7D7068]">
                                    <div>
                                        <DropMark className="mx-auto mb-3 size-8 animate-pulse text-[#3A302B]" />
                                        Your app will appear here in a moment.
                                    </div>
                                </div>
                            ) : (
                                <div className="animate-in space-y-4 duration-500 fade-in">
                                    <div className="flex items-center justify-between">
                                        <p className="font-display text-lg font-bold text-[#F5EFEA]">
                                            Time off
                                        </p>
                                        <span className="text-xs text-[#B3A69C]">
                                            October
                                        </span>
                                    </div>

                                    <div className="flex items-center gap-3 rounded-xl bg-[#151110] p-4 ring-1 ring-[#2A2320]">
                                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[#FF4D1C]/20 text-xs font-semibold text-[#FF9A5C]">
                                            PS
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-[#F5EFEA]">
                                                Priya Shah
                                            </p>
                                            <p className="text-xs text-[#B3A69C]">
                                                Oct 14 – 18 · 5 days
                                            </p>
                                        </div>
                                        {isApproved ? (
                                            <span className="flex animate-in items-center gap-1 rounded-lg bg-[#3DDC97]/15 px-3 py-1.5 text-xs font-semibold text-[#3DDC97] duration-300 zoom-in-95">
                                                <Check className="size-3.5" />
                                                Approved
                                            </span>
                                        ) : (
                                            <span className="rounded-lg bg-[#FF4D1C] px-3 py-1.5 text-xs font-semibold text-white">
                                                Approve
                                            </span>
                                        )}
                                    </div>

                                    {showsReport && (
                                        <div className="animate-in rounded-xl bg-[#151110] p-4 ring-1 ring-[#2A2320] duration-500 fade-in">
                                            <div className="mb-3 flex items-baseline justify-between">
                                                <p className="text-sm font-medium text-[#F5EFEA]">
                                                    Out this week
                                                </p>
                                                <p className="text-xs text-[#B3A69C]">
                                                    7 people
                                                </p>
                                            </div>
                                            <div className="flex h-20 items-end gap-2">
                                                {[62, 80, 45, 90, 70].map(
                                                    (height, index) => (
                                                        <div
                                                            key={index}
                                                            className="flex h-full flex-1 flex-col justify-end"
                                                        >
                                                            <div
                                                                className="w-full rounded-md bg-[#FF4D1C]"
                                                                style={{
                                                                    height: `${height}%`,
                                                                    opacity:
                                                                        index ===
                                                                        3
                                                                            ? 1
                                                                            : 0.35,
                                                                }}
                                                            />
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                            <div className="mt-1.5 flex gap-2 text-center text-[10px] text-[#7D7068]">
                                                {[
                                                    'Mon',
                                                    'Tue',
                                                    'Wed',
                                                    'Thu',
                                                    'Fri',
                                                ].map((day) => (
                                                    <span
                                                        key={day}
                                                        className="flex-1"
                                                    >
                                                        {day}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {!prefersReducedMotion && (
                <button
                    type="button"
                    onClick={() => setIsPaused((paused) => !paused)}
                    className="absolute -bottom-11 left-0 flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-[#B3A69C] hover:text-white focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
                >
                    {isPaused ? (
                        <Play className="size-3.5" />
                    ) : (
                        <Pause className="size-3.5" />
                    )}
                    {isPaused ? 'Play demo' : 'Pause demo'}
                </button>
            )}
        </div>
    );
}

/**
 * Deterministic pseudo-random numbers, so the impact tracks look the same on every load.
 */
function seededRandom(seed: number): () => number {
    let state = seed;

    return () => {
        state = (state + 0x6d2b79f5) | 0;
        let t = Math.imul(state ^ (state >>> 15), 1 | state);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function buildImpactTracks(): string[] {
    const random = seededRandom(7);

    return Array.from({ length: 12 }, (_, index) => {
        const angle = (index / 12) * Math.PI * 2 + random() * 0.4;
        const length = 160 + random() * 240;
        const curl = (random() > 0.5 ? 1 : -1) * (0.9 + random() * 1.4);
        const point = (reach: number, twist: number) => {
            const theta = angle + twist;

            return `${(500 + Math.cos(theta) * reach).toFixed(1)} ${(500 + Math.sin(theta) * reach).toFixed(1)}`;
        };

        return `M500 500 C${point(length * 0.45, curl * 0.15)} ${point(length * 0.9, curl * 0.55)} ${point(length, curl)}`;
    });
}

const IMPACT_TRACKS = buildImpactTracks();

/**
 * The flash and bubble-chamber tracks at the moment the drop lands.
 */
function ImpactTracks() {
    return (
        <div className="pointer-events-none motion-reduce:hidden">
            <div
                className="absolute top-1/2 left-1/2 size-[420px] -translate-x-1/2 -translate-y-1/2 animate-impact-flash rounded-full bg-[radial-gradient(circle,rgba(255,225,209,0.9),rgba(255,77,28,0.45)_35%,transparent_70%)] opacity-0"
                style={{ animationDelay: `${IMPACT_DELAY_MS}ms` }}
            />
            <svg viewBox="0 0 1000 1000" className="absolute inset-0">
                {IMPACT_TRACKS.map((track, index) => (
                    <path
                        key={index}
                        d={track}
                        pathLength={1}
                        fill="none"
                        stroke={index % 3 === 0 ? '#FFE1D1' : '#FF9A5C'}
                        strokeWidth={index % 3 === 0 ? 1.5 : 1}
                        strokeLinecap="round"
                        strokeDasharray="1"
                        className="animate-track-draw opacity-0"
                        style={{
                            animationDelay: `${IMPACT_DELAY_MS + index * 25}ms`,
                        }}
                    />
                ))}
            </svg>
        </div>
    );
}

const HERO_PROMPT = 'A booking app for our meeting rooms';
const HERO_PROMPT_TYPING_AT_MS = 450;
const HERO_PROMPT_TYPE_SPEED_MS = 48;
const HERO_PROMPT_SENT_AT_MS = DROP_START_MS - 120;

/**
 * A small prompt above the landing point that types one sentence and sends
 * it; the drop falls out from under it.
 */
function HeroPrompt() {
    const [elapsed, setElapsed] = useState(0);

    useEffect(() => {
        const startedAt = performance.now();
        let frame = 0;

        const tick = () => {
            const now = performance.now() - startedAt;
            setElapsed(now);

            if (now < DROP_START_MS + 800) {
                frame = requestAnimationFrame(tick);
            }
        };

        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, []);

    const typedPrompt = HERO_PROMPT.slice(
        0,
        Math.max(
            0,
            Math.floor(
                (elapsed - HERO_PROMPT_TYPING_AT_MS) /
                    HERO_PROMPT_TYPE_SPEED_MS,
            ),
        ),
    );
    const isPressing = elapsed >= HERO_PROMPT_SENT_AT_MS - 220;
    const isSent = elapsed >= HERO_PROMPT_SENT_AT_MS;

    return (
        <div
            className={`absolute bottom-[calc(50%+150px)] left-1/2 hidden w-80 -translate-x-1/2 animate-in items-center gap-2 rounded-full bg-[#151110]/90 py-1.5 pr-1.5 pl-4 text-sm shadow-[0_20px_50px_-15px_rgba(255,77,28,0.5)] ring-1 ring-[#3A302B] backdrop-blur transition-all duration-500 fade-in motion-reduce:hidden sm:flex ${isSent ? '-translate-y-4 scale-95 opacity-0' : ''}`}
        >
            <span className="min-w-0 flex-1 truncate">
                {typedPrompt.length === 0 ? (
                    <span className="text-[#7D7068]">Describe your app…</span>
                ) : (
                    <span className="text-[#F5EFEA]">
                        {typedPrompt}
                        <span className="ml-px inline-block h-4 w-px translate-y-0.5 animate-pulse bg-[#F5EFEA]" />
                    </span>
                )}
            </span>
            <span
                className={`grid size-7 shrink-0 place-items-center rounded-full bg-[#FF4D1C] text-white transition duration-200 ${isPressing ? 'scale-90 shadow-[0_0_20px_rgba(255,77,28,0.9)] brightness-125' : ''}`}
            >
                <ArrowUp className="size-4" />
            </span>
        </div>
    );
}

const RIPPLE_RADII = [70, 130, 200, 280, 370, 470];

function HeroRipple({
    centerRef,
}: {
    centerRef: RefObject<HTMLDivElement | null>;
}) {
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 overflow-hidden"
        >
            <GravityField centerRef={centerRef} />
            <div
                ref={centerRef}
                className="absolute top-[230px] left-[78%] size-[1000px] -translate-x-1/2 -translate-y-1/2"
            >
                <div className="absolute inset-[12%] rounded-full bg-[radial-gradient(closest-side,rgba(255,77,28,0.25)_35%,transparent)]" />
                <svg viewBox="0 0 1000 1000" className="absolute inset-0">
                    <defs>
                        <radialGradient id="ripple-stroke">
                            <stop offset="0%" stopColor="#FFB27A" />
                            <stop offset="100%" stopColor="#FF4D1C" />
                        </radialGradient>
                    </defs>
                    {RIPPLE_RADII.map((radius, index) => (
                        <circle
                            key={radius}
                            cx="500"
                            cy="500"
                            r={radius}
                            fill="none"
                            stroke="url(#ripple-stroke)"
                            strokeWidth={index === 0 ? 2 : 1.5}
                            strokeOpacity={0.55 - index * 0.08}
                            className="origin-center animate-ripple-in [transform-box:fill-box] motion-reduce:animate-none"
                            style={{
                                animationDelay: `${IMPACT_DELAY_MS - 10 + index * 130}ms`,
                            }}
                        />
                    ))}
                    <circle
                        cx="500"
                        cy="500"
                        r="470"
                        fill="none"
                        stroke="#FFB27A"
                        strokeWidth="3"
                        className="origin-center animate-ripple-splash opacity-0 [transform-box:fill-box] motion-reduce:hidden"
                        style={{ animationDelay: `${IMPACT_DELAY_MS - 10}ms` }}
                    />
                </svg>
                <Galaxy>
                    <BlackHole />
                </Galaxy>
                <HeroPrompt />
                <DropMark
                    className="absolute top-1/2 left-1/2 size-9 -translate-x-1/2 -translate-y-[85%] animate-drop-fall text-[#FFB27A] drop-shadow-[0_0_18px_rgba(255,178,122,0.9)] motion-reduce:hidden"
                    style={{ animationDelay: `${DROP_START_MS}ms` }}
                />
                <ImpactTracks />
            </div>
        </div>
    );
}

function PrimaryCta({ isLoggedIn }: { isLoggedIn: boolean }) {
    return (
        <Link
            href={isLoggedIn ? dashboard() : register()}
            data-test="primary-cta"
            className={`inline-flex items-center justify-center rounded-xl bg-gradient-to-b from-[#FF6A2B] to-[#E8341C] px-6 py-3.5 text-base font-semibold text-white shadow-[0_12px_32px_-10px_rgba(255,77,28,0.9),inset_0_1px_0_rgba(255,255,255,0.3)] transition hover:brightness-110 focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0A0807] focus-visible:outline-none`}
        >
            {isLoggedIn ? 'Open your dashboard' : 'Build your first app'}
        </Link>
    );
}

export default function Welcome() {
    const { auth } = usePage().props;
    const isLoggedIn = Boolean(auth.user);
    const dropRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const previousBackground =
            document.documentElement.style.backgroundColor;
        document.documentElement.style.backgroundColor = '#0A0807';

        return () => {
            document.documentElement.style.backgroundColor = previousBackground;
        };
    }, []);

    return (
        <>
            <Head title={`${BRAND}: vibe-code apps for production`} />

            <div className="min-h-screen bg-[#0A0807] font-sans text-[#F5EFEA] antialiased [color-scheme:dark]">
                <ParticleUniverse originRef={dropRef} />
                <SiteHeader isLoggedIn={isLoggedIn} />

                <main>
                    <section className="relative overflow-hidden">
                        <HeroRipple centerRef={dropRef} />

                        <div className="relative mx-auto max-w-6xl px-6 pt-14 pb-24 md:pt-20">
                            <div className="max-w-3xl">
                                <h1 className="font-display text-5xl leading-[0.95] font-extrabold tracking-[-0.035em] text-balance sm:text-6xl lg:text-[5.25rem]">
                                    <SparklesText>
                                        <span className="block bg-gradient-to-b from-white via-white to-[#FFC7A8] bg-clip-text pb-2 text-transparent">
                                            Vibe-code apps
                                            <br />
                                            for production.
                                        </span>
                                    </SparklesText>
                                </h1>
                                <p className="mt-6 max-w-xl text-lg leading-relaxed text-[#B3A69C] sm:text-xl">
                                    Describe what you need. Get real, tested
                                    code, running anywhere you want.
                                </p>
                                <div className="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <PrimaryCta isLoggedIn={isLoggedIn} />
                                    <DemoVideo brand={BRAND} />
                                    <a
                                        href="#how-it-works"
                                        className="inline-flex items-center justify-center rounded-xl px-6 py-3.5 font-semibold text-[#F5EFEA] ring-1 ring-[#3A302B] hover:bg-[#151110]"
                                    >
                                        See how it works
                                    </a>
                                </div>
                                <p className="mt-4 text-sm text-[#B3A69C]">
                                    Free to self-host. Source available. Bring
                                    your own subscription. Deploy anywhere.
                                </p>
                            </div>

                            <div
                                id="demo"
                                className="relative mt-16 scroll-mt-24"
                            >
                                <div
                                    aria-hidden="true"
                                    className="pointer-events-none absolute -inset-x-16 -top-24 bottom-1/3 bg-[radial-gradient(50%_60%_at_50%_40%,rgba(255,77,28,0.45),transparent)]"
                                />
                                <BuildDemo />
                            </div>

                            <div
                                id="integrations"
                                className="mt-28 scroll-mt-24"
                            >
                                <h2 className="text-center text-sm font-medium text-[#B3A69C]">
                                    Built on tools your IT team already trusts
                                </h2>
                                <ul className="mt-8 flex flex-wrap gap-x-12 gap-y-10">
                                    {INTEGRATIONS.map((group) => (
                                        <li
                                            key={group.role}
                                            className="grow border-t border-[#2A2320] pt-5"
                                        >
                                            <p className="text-xs text-[#7D7068]">
                                                {group.role}
                                            </p>
                                            <ul className="mt-3 flex flex-wrap gap-x-6 gap-y-3">
                                                {group.tools.map((tool) => (
                                                    <li key={tool.name}>
                                                        <a
                                                            href={tool.url}
                                                            className="flex items-center gap-2 font-display text-lg font-bold text-[#F5EFEA] transition-colors hover:text-white"
                                                        >
                                                            <img
                                                                src={tool.logo}
                                                                alt=""
                                                                className="size-6"
                                                            />
                                                            {tool.name}
                                                        </a>
                                                    </li>
                                                ))}
                                            </ul>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                        <CosmicZoomControl className="absolute top-[430px] left-[78%] z-10 hidden -translate-x-1/2 xl:flex" />
                    </section>

                    <section className="bg-[#151110]">
                        <div className="mx-auto max-w-6xl px-6 py-24">
                            <h2 className="max-w-2xl font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                                Every team has an app it needs and can’t get.
                            </h2>
                            <div className="mt-12 grid gap-10 md:grid-cols-3">
                                {[
                                    {
                                        quote: '“It’s on the IT roadmap. For next year.”',
                                        body: 'Small internal tools never make the cut, so the work gets done the slow way.',
                                    },
                                    {
                                        quote: '“The whole process runs on a spreadsheet only Dana understands.”',
                                        body: 'It works until Dana goes on vacation, or someone sorts one column.',
                                    },
                                    {
                                        quote: '“The AI prototype looked great. Then IT asked where it would run.”',
                                        body: `Prototype builders stop at the demo. ${BRAND} writes real, tested code and runs it on your own servers, so there’s no wall between the demo and done.`,
                                    },
                                ].map((pain) => (
                                    <figure
                                        key={pain.quote}
                                        className="border-l-2 border-[#FF4D1C] pl-5"
                                    >
                                        <blockquote className="font-display text-xl leading-snug font-semibold">
                                            {pain.quote}
                                        </blockquote>
                                        <figcaption className="mt-3 leading-relaxed text-[#B3A69C]">
                                            {pain.body}
                                        </figcaption>
                                    </figure>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section
                        id="how-it-works"
                        className="mx-auto max-w-6xl scroll-mt-20 px-6 py-24"
                    >
                        <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                            From idea to link in four steps
                        </h2>
                        <p className="mt-4 max-w-xl text-lg text-[#B3A69C]">
                            Nothing to install and nothing to learn. If you can
                            write an email, you can do this.
                        </p>
                        <ol className="mt-14 grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-4">
                            {STEPS.map((step, index) => (
                                <li key={step.title} className="relative">
                                    {index < STEPS.length - 1 && (
                                        <span
                                            aria-hidden="true"
                                            className="absolute top-5 right-[-1rem] left-14 hidden h-px bg-gradient-to-r from-[#FF4D1C]/80 to-[#2A2320] lg:block"
                                        />
                                    )}
                                    <span className="grid size-10 place-items-center rounded-full bg-[#FF4D1C] font-display text-lg font-bold text-white shadow-[0_0_24px_rgba(255,77,28,0.6)] ring-4 ring-[#FF4D1C]/15">
                                        {index + 1}
                                    </span>
                                    <h3 className="mt-5 font-display text-xl font-bold">
                                        {step.title}
                                    </h3>
                                    <p className="mt-2 leading-relaxed text-[#B3A69C]">
                                        {step.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <section
                        id="features"
                        className="scroll-mt-20 border-t border-[#2A2320]"
                    >
                        <div className="mx-auto max-w-6xl px-6 py-24">
                            <div className="grid gap-12 lg:grid-cols-[minmax(0,4fr)_minmax(0,8fr)]">
                                <div className="lg:sticky lg:top-10 lg:self-start">
                                    <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                                        Everything between “I wish we had…” and
                                        “here’s the link”
                                    </h2>
                                    <p className="mt-4 text-lg leading-relaxed text-[#B3A69C]">
                                        Simple enough for anyone on the team.
                                        Open enough that your developers will
                                        actually trust it.
                                    </p>
                                </div>
                                <dl className="grid gap-x-10 sm:grid-cols-2">
                                    {FEATURES.map((feature) => (
                                        <div
                                            key={feature.id}
                                            id={feature.id}
                                            className="scroll-mt-24 border-t border-[#2A2320] py-7 transition-colors duration-700 target:border-[#FF4D1C]"
                                        >
                                            <dt className="flex items-center gap-3 font-display text-lg font-bold">
                                                <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-gradient-to-b from-[#FF4D1C]/30 to-[#FF4D1C]/10 ring-1 ring-[#FF4D1C]/30">
                                                    <feature.icon
                                                        aria-hidden="true"
                                                        className="size-[18px] text-[#FFB27A]"
                                                    />
                                                </span>
                                                {feature.title}
                                            </dt>
                                            <dd className="mt-2 pl-12 leading-relaxed text-[#B3A69C]">
                                                {feature.body}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </div>
                    </section>

                    <section
                        id="compare"
                        className="scroll-mt-16 border-y border-[#2A2320] bg-[#100D0B] text-white"
                    >
                        <div className="mx-auto grid max-w-6xl gap-14 px-6 py-24 lg:grid-cols-2 lg:items-center">
                            {/* min-w-0: the install command scrolls inside its box instead of widening the page. */}
                            <div className="min-w-0">
                                <Server
                                    aria-hidden="true"
                                    className="size-8 text-[#FF9A5C]"
                                />
                                <h2 className="mt-6 font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                                    Your apps. Your servers. Your AI.
                                </h2>
                                <p className="mt-5 text-lg leading-relaxed text-[#B3A69C]">
                                    Hosted builders rent you a workspace on
                                    their cloud and resell you AI credits.{' '}
                                    {BRAND} runs on your laptop or your own
                                    server and builds on your own AI plan or
                                    key, so your apps, your data, and your bill
                                    stay yours. One command installs it on your
                                    laptop:
                                </p>
                                <InstallCommand />
                            </div>
                            <div className="overflow-hidden rounded-2xl ring-1 ring-white/10">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="bg-white/5">
                                            <th className="px-5 py-4 font-medium text-[#B3A69C]">
                                                <span className="sr-only">
                                                    Topic
                                                </span>
                                            </th>
                                            <th className="bg-[#FF4D1C]/20 px-5 py-4 font-display text-base font-bold">
                                                {BRAND}
                                            </th>
                                            <th className="px-5 py-4 font-medium text-[#B3A69C]">
                                                Hosted builders
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {COMPARISON.map((row) => (
                                            <tr
                                                key={row.topic}
                                                className="border-t border-white/10"
                                            >
                                                <th
                                                    scope="row"
                                                    className="px-5 py-4 font-normal text-[#B3A69C]"
                                                >
                                                    {row.topic}
                                                </th>
                                                <td className="bg-[#FF4D1C]/10 px-5 py-4 font-medium">
                                                    {row.us}
                                                </td>
                                                <td className="px-5 py-4 text-[#7D7068]">
                                                    {row.them}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section
                        id="faq"
                        className="mx-auto max-w-3xl scroll-mt-20 px-6 py-24"
                    >
                        <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                            Questions people ask first
                        </h2>
                        <div className="mt-10 divide-y divide-[#2A2320] border-y border-[#2A2320]">
                            {FAQS.map((faq) => (
                                <details key={faq.question} className="group">
                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-6 py-5 font-display text-lg font-bold focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                                        {faq.question}
                                        <Plus
                                            aria-hidden="true"
                                            className="size-5 shrink-0 text-[#FF9A5C] transition-transform group-open:rotate-45"
                                        />
                                    </summary>
                                    <p className="pb-6 leading-relaxed text-[#B3A69C]">
                                        {faq.answer}
                                    </p>
                                </details>
                            ))}
                        </div>
                    </section>

                    <section className="px-6 pb-24">
                        <div className="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-[radial-gradient(120%_120%_at_50%_0%,#FF8A3D_0%,#FF4D1C_45%,#B8200F_100%)] px-8 py-24 text-center text-white shadow-[inset_0_1px_0_rgba(255,255,255,0.35),0_40px_100px_-40px_rgba(255,77,28,0.7)]">
                            <svg
                                aria-hidden="true"
                                viewBox="0 0 800 800"
                                className="pointer-events-none absolute top-1/2 left-1/2 w-[1100px] -translate-x-1/2 -translate-y-1/2 text-white opacity-15"
                            >
                                {[60, 130, 210, 300, 390].map((radius) => (
                                    <circle
                                        key={radius}
                                        cx="400"
                                        cy="400"
                                        r={radius}
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.5"
                                    />
                                ))}
                            </svg>
                            <div className="relative">
                                <h2 className="mx-auto max-w-2xl font-display text-4xl font-extrabold tracking-[-0.03em] text-balance sm:text-5xl">
                                    What would you build if it only took a
                                    sentence?
                                </h2>
                                <p className="mx-auto mt-5 max-w-lg text-lg text-[#FFE1D1]">
                                    Describe it now. You’ll be clicking around
                                    in it before your coffee gets cold.
                                </p>
                                <Link
                                    href={isLoggedIn ? dashboard() : register()}
                                    className="mt-9 inline-flex items-center justify-center rounded-xl bg-white px-7 py-4 text-base font-semibold text-[#0A0807] transition-colors hover:bg-[#F5EFEA] focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#FF4D1C] focus-visible:outline-none"
                                >
                                    {isLoggedIn
                                        ? 'Open your dashboard'
                                        : 'Build your first app'}
                                </Link>
                            </div>
                        </div>
                    </section>
                </main>

                <SiteFooter />
            </div>
        </>
    );
}
