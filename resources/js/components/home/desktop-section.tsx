import { ArrowRight, BookOpen, Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DESKTOP_DOCS_URL, DESKTOP_DOWNLOADS } from '@/lib/links';

type System = 'mac' | 'windows' | 'linux';

const PRIMARY: Record<System, { label: string; href: string }> = {
    mac: { label: 'Download for Mac', href: DESKTOP_DOWNLOADS.macAppleSilicon },
    windows: { label: 'Download for Windows', href: DESKTOP_DOWNLOADS.windows },
    linux: {
        label: 'Download for Linux',
        href: DESKTOP_DOWNLOADS.linuxAppImage,
    },
};

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

const POINTS = [
    'Sign in to any OneDrop, on your laptop or your team’s server, with your browser.',
    'Everything the web app has: the agent, the preview, Tools, the Shell and Files.',
    'Notifications when an agent is done, downloads in your Downloads folder.',
    'Keeps itself up to date.',
];

/** The visitor's system, from the browser; Mac until it's known (the page renders on the server too). */
function useSystem(): System {
    const [system, setSystem] = useState<System>('mac');

    useEffect(() => {
        const platform =
            `${navigator.platform} ${navigator.userAgent}`.toLowerCase();

        if (/win(dows|32|64)/.test(platform)) {
            setSystem('windows');
        } else if (
            platform.includes('linux') &&
            !platform.includes('android')
        ) {
            setSystem('linux');
        }
    }, []);

    return system;
}

/** The home page's desktop app section: a download for the visitor's system, and the rest (DESK-004). */
export function DesktopSection() {
    const system = useSystem();
    const primary = PRIMARY[system];

    return (
        <section
            id="desktop"
            className="scroll-mt-16 border-b border-[#2A2320]"
        >
            <div className="mx-auto grid max-w-6xl gap-14 px-6 py-24 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                <div>
                    <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                        Or use it from your desktop
                    </h2>
                    <p className="mt-4 text-lg leading-relaxed text-[#B3A69C]">
                        The OneDrop app for Mac, Windows and Linux: your
                        projects in a window of their own.
                    </p>
                    <ul className="mt-8 space-y-3">
                        {POINTS.map((point) => (
                            <li
                                key={point}
                                className="flex gap-3 leading-relaxed text-[#B3A69C]"
                            >
                                <span
                                    aria-hidden="true"
                                    className="mt-2.5 size-1.5 shrink-0 rounded-full bg-[#FF9A5C]"
                                />
                                {point}
                            </li>
                        ))}
                    </ul>
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
                    {system === 'mac' && (
                        <p className="mt-3 text-sm text-[#7D7068]">
                            For Apple Silicon Macs. On an Intel Mac, download
                            the Intel version below.
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
                        It connects to a OneDrop you run: install one above
                        first, or ask your team for its address.
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
        </section>
    );
}
