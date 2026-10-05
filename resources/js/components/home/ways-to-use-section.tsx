import { Link } from '@inertiajs/react';
import { ArrowRight, Download, Globe, Monitor, Server } from 'lucide-react';
import type { ReactNode } from 'react';
import { InstallCommand } from '@/components/home/install-command';
import { useDesktopDownload } from '@/hooks/use-desktop-download';
import { INSTALL_COMMAND } from '@/lib/links';
import { dashboard, pricing, register } from '@/routes';

const moreLinkClass =
    'mt-4 inline-flex items-center gap-1.5 text-sm text-[#B3A69C] underline-offset-4 hover:text-white hover:underline focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none';

const buttonClass =
    'inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 font-semibold transition focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0A0807] focus-visible:outline-none';

function Way({
    id,
    icon: Icon,
    title,
    badge,
    body,
    children,
}: {
    id: string;
    icon: typeof Globe;
    title: string;
    badge?: string;
    body: string;
    children: ReactNode;
}) {
    return (
        <li
            data-test={`way-${id}`}
            className={`flex min-w-0 flex-col rounded-2xl p-6 ring-1 ${badge ? 'bg-[#FF4D1C]/[0.07] ring-[#FF4D1C]/40' : 'bg-[#151110] ring-[#2A2320]'}`}
        >
            <div className="flex items-center justify-between gap-3">
                <span className="grid size-10 place-items-center rounded-lg bg-gradient-to-b from-[#FF4D1C]/30 to-[#FF4D1C]/10 ring-1 ring-[#FF4D1C]/30">
                    <Icon
                        aria-hidden="true"
                        className="size-5 text-[#FFB27A]"
                    />
                </span>
                {badge && (
                    <span className="rounded-full bg-[#FF4D1C]/15 px-2.5 py-1 text-xs font-semibold text-[#FF9A5C] ring-1 ring-[#FF4D1C]/40">
                        {badge}
                    </span>
                )}
            </div>
            <h3 className="mt-5 font-display text-xl font-bold">{title}</h3>
            <p className="mt-2 flex-1 leading-relaxed text-[#B3A69C]">{body}</p>
            <div className="mt-6">{children}</div>
        </li>
    );
}

/**
 * The home page's three ways to use OneDrop, side by side, each with what it takes to start (HOME-005): the browser
 * is where most people start; the desktop app's download and the install command are right here, with the rest
 * (every download, servers and own domains) in their sections further down.
 */
export function WaysToUseSection({ isLoggedIn }: { isLoggedIn: boolean }) {
    const download = useDesktopDownload();

    return (
        <section id="ways" className="scroll-mt-16">
            <div className="mx-auto max-w-6xl px-6 py-24">
                <h2 className="max-w-2xl font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                    Use it wherever suits you
                </h2>
                <p className="mt-4 max-w-2xl text-lg leading-relaxed text-[#B3A69C]">
                    Most people start in the browser. The desktop app and
                    self-hosting are there when you want them.
                </p>
                <ul className="mt-12 grid gap-4 lg:grid-cols-3">
                    <Way
                        id="browser"
                        icon={Globe}
                        title="In your browser"
                        badge="Start here"
                        body="Nothing to install. Sign up and start building on free AI credits, no AI account needed. Bring your own AI whenever you like."
                    >
                        <Link
                            href={isLoggedIn ? dashboard() : register()}
                            className={`${buttonClass} bg-gradient-to-b from-[#FF6A2B] to-[#E8341C] text-white shadow-[0_12px_32px_-10px_rgba(255,77,28,0.9),inset_0_1px_0_rgba(255,255,255,0.3)] hover:brightness-110`}
                        >
                            {isLoggedIn
                                ? 'Open your dashboard'
                                : 'Build your first app'}
                        </Link>
                        <div>
                            <Link href={pricing()} className={moreLinkClass}>
                                Free to start. See pricing
                                <ArrowRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </Link>
                        </div>
                    </Way>

                    <Way
                        id="desktop"
                        icon={Monitor}
                        title="On your desktop"
                        body="Everything in the browser, plus your own network, your editor, your project on localhost, and running projects in your own Docker."
                    >
                        <a
                            href={download.href}
                            data-test="way-desktop-download"
                            className={`${buttonClass} text-[#F5EFEA] ring-1 ring-[#3A302B] hover:bg-white/[0.04]`}
                        >
                            <Download aria-hidden="true" className="size-4" />
                            {download.label}
                        </a>
                        <div>
                            <a href="#desktop" className={moreLinkClass}>
                                Other systems and what it does
                                <ArrowRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </a>
                        </div>
                    </Way>

                    <Way
                        id="self-host"
                        icon={Server}
                        title="On your own servers"
                        body="Free and source available, with every feature and no limits. One command runs it on your Mac or Linux laptop; Docker is all it needs."
                    >
                        <InstallCommand
                            command={INSTALL_COMMAND}
                            name="way-install-command"
                        />
                        <a href="#install" className={moreLinkClass}>
                            On a server or your own domain
                            <ArrowRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        </a>
                    </Way>
                </ul>
            </div>
        </section>
    );
}
