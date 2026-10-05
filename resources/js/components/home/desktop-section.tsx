import { Link, usePage } from '@inertiajs/react';
import {
    AppWindow,
    ArrowRight,
    BookOpen,
    Container,
    Download,
    Network,
    PanelTop,
    Plug,
    SquareCode,
} from 'lucide-react';
import { useDesktopDownload } from '@/hooks/use-desktop-download';
import { DESKTOP_DOCS_URL, DESKTOP_DOWNLOADS } from '@/lib/links';
import { register } from '@/routes';

const ALL_DOWNLOADS = [
    {
        id: 'mac-apple-silicon',
        label: 'Mac (Apple Silicon)',
        href: DESKTOP_DOWNLOADS.macAppleSilicon,
    },
    { id: 'mac-intel', label: 'Mac (Intel)', href: DESKTOP_DOWNLOADS.macIntel },
    { id: 'windows', label: 'Windows', href: DESKTOP_DOWNLOADS.windows },
    {
        id: 'linux-appimage',
        label: 'Linux (AppImage)',
        href: DESKTOP_DOWNLOADS.linuxAppImage,
    },
    {
        id: 'linux-deb',
        label: 'Linux (.deb)',
        href: DESKTOP_DOWNLOADS.linuxDeb,
    },
];

/** What only the app can do (DESK-007..011), then what it shares with the web. */
const FEATURES = [
    {
        icon: Network,
        title: 'Reach your own network',
        body: 'The agent and the preview can use hosts only your computer reaches: your intranet, a database behind the VPN, something on localhost.',
    },
    {
        icon: Plug,
        title: 'Your project on localhost',
        body: 'The app and its database on your own ports, for your browser, TablePlus or psql, even with the window closed.',
    },
    {
        icon: SquareCode,
        title: 'Open it in your editor',
        body: 'One click opens the project in VS Code or Cursor, straight into its sandbox. No SSH setup.',
    },
    {
        icon: Container,
        title: 'Run it on your computer',
        body: 'Move a project into your own Docker: fast, private, and free to run. Teammates still open its preview.',
    },
    {
        icon: PanelTop,
        title: 'In your menu bar',
        body: 'See which agents are working or waiting for you, and start a project from anywhere with ⌘⌥O.',
    },
    {
        icon: AppWindow,
        title: 'Everything else, too',
        body: 'The same account, projects, agent, preview, Tools, Shell and Files as the web, with notifications and updates on its own.',
    },
];

/** The home page's desktop app section: a download for the visitor's system, and the rest (DESK-004). */
export function DesktopSection() {
    const { auth } = usePage().props;
    const primary = useDesktopDownload();

    return (
        <section
            id="desktop"
            className="scroll-mt-16 border-b border-[#2A2320]"
        >
            <div className="mx-auto max-w-6xl px-6 py-24">
                <div className="grid gap-14 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                    <div className="self-center">
                        <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                            The desktop app: your computer, plugged in
                        </h2>
                        <p className="mt-4 text-lg leading-relaxed text-[#B3A69C]">
                            OneDrop for Mac, Windows and Linux. The same account
                            and projects as the web, plus what a browser
                            can&apos;t do: reach your network, your ports, your
                            editor and your own Docker.
                        </p>
                    </div>

                    <div className="min-w-0 self-center rounded-2xl bg-[#151110] p-5 ring-1 ring-[#2A2320] sm:p-6">
                        <a
                            href={primary.href}
                            data-test="desktop-download"
                            className="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A2B] to-[#E8341C] px-6 py-3.5 text-base font-semibold text-white shadow-[0_12px_32px_-10px_rgba(255,77,28,0.9),inset_0_1px_0_rgba(255,255,255,0.3)] transition hover:brightness-110 focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:ring-offset-2 focus-visible:ring-offset-[#151110] focus-visible:outline-none"
                        >
                            <Download aria-hidden="true" className="size-5" />
                            {primary.label}
                        </a>
                        {primary.system === 'mac' && (
                            <p className="mt-3 text-sm text-[#7D7068]">
                                For Apple Silicon Macs. On an Intel Mac,
                                download the Intel version below.
                            </p>
                        )}

                        <h3 className="mt-8 border-t border-[#2A2320] pt-6 text-sm font-medium text-[#B3A69C]">
                            All downloads
                        </h3>
                        <ul className="mt-3 flex flex-wrap gap-2">
                            {ALL_DOWNLOADS.map((download) => (
                                <li key={download.id}>
                                    <a
                                        href={download.href}
                                        data-test={`desktop-download-${download.id}`}
                                        className="inline-flex items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-1.5 text-sm text-[#F5EFEA] ring-1 ring-white/10 hover:bg-white/[0.08] focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
                                    >
                                        <Download
                                            aria-hidden="true"
                                            className="size-3.5 text-[#7D7068]"
                                        />
                                        {download.label}
                                    </a>
                                </li>
                            ))}
                        </ul>

                        <p className="mt-6 text-sm text-[#7D7068]">
                            Sign in with your OneDrop account
                            {!auth.user && (
                                <>
                                    {' '}
                                    (no account yet?{' '}
                                    <Link
                                        href={register()}
                                        className="text-[#FF9A5C] underline-offset-4 hover:underline"
                                    >
                                        create one free
                                    </Link>
                                    )
                                </>
                            )}
                            , or with the address of a OneDrop your team runs.
                        </p>

                        <a
                            href={DESKTOP_DOCS_URL}
                            className="mt-6 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-[#F5EFEA] ring-1 ring-[#3A302B] hover:bg-white/[0.04] focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
                        >
                            <BookOpen aria-hidden="true" className="size-4" />
                            Desktop app guide
                            <ArrowRight
                                aria-hidden="true"
                                className="size-3.5 text-[#7D7068]"
                            />
                        </a>
                    </div>
                </div>

                <ul className="mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {FEATURES.map((feature) => (
                        <li
                            key={feature.title}
                            className="rounded-2xl bg-[#151110] p-6 ring-1 ring-[#2A2320]"
                        >
                            <span className="grid size-9 place-items-center rounded-lg bg-gradient-to-b from-[#FF4D1C]/30 to-[#FF4D1C]/10 ring-1 ring-[#FF4D1C]/30">
                                <feature.icon
                                    aria-hidden="true"
                                    className="size-[18px] text-[#FFB27A]"
                                />
                            </span>
                            <h3 className="mt-4 font-display text-lg font-bold">
                                {feature.title}
                            </h3>
                            <p className="mt-2 leading-relaxed text-[#B3A69C]">
                                {feature.body}
                            </p>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
