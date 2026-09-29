import { Head, Link, router, usePage } from '@inertiajs/react';
import { ExternalLink, MessageSquareText, Pencil, Shuffle } from 'lucide-react';
import { useEffect } from 'react';
import ShareController from '@/actions/App/Http/Controllers/ShareController';
import { SiteFooter } from '@/components/home/site-footer';
import { SiteHeader } from '@/components/home/site-header';
import { register } from '@/routes';
import type { SharePage } from '@/types';

/**
 * A shared project's public page: the prompt, what it built, and "Remix this" (SHARE-001, SHARE-002).
 */
export default function ShowShare({
    share,
    workspaceUrl,
}: {
    share: SharePage;
    /** The project's workspace, when the owner is looking at their own page. */
    workspaceUrl: string | null;
}) {
    const { auth } = usePage().props;
    const isLoggedIn = Boolean(auth.user);
    const host = share.app_url?.replace(/^https?:\/\//, '') ?? share.name;

    useEffect(() => {
        const previousBackground =
            document.documentElement.style.backgroundColor;
        document.documentElement.style.backgroundColor = '#0A0807';

        return () => {
            document.documentElement.style.backgroundColor = previousBackground;
        };
    }, []);

    const remix = () => router.post(ShareController.remix.url(share.slug));

    return (
        <>
            <Head title={`${share.name}, built with OneDrop`} />

            <div className="min-h-screen bg-[#0A0807] font-sans text-[#F5EFEA] antialiased [color-scheme:dark]">
                <SiteHeader isLoggedIn={isLoggedIn} />

                <main>
                    <section className="relative overflow-hidden">
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-x-0 -top-40 h-[520px] bg-[radial-gradient(50%_60%_at_50%_0%,rgba(255,77,28,0.28),transparent_70%)]"
                        />

                        <div className="relative mx-auto max-w-5xl px-6 pt-14 pb-16 md:pt-20">
                            <p className="text-sm font-semibold tracking-[0.14em] text-[#FF9A5C] uppercase">
                                Built with OneDrop
                            </p>
                            <h1
                                className="mt-3 bg-gradient-to-b from-white via-white to-[#FFC7A8] bg-clip-text pb-2 font-display text-5xl leading-[0.95] font-extrabold tracking-[-0.035em] text-balance text-transparent sm:text-6xl"
                                data-test="share-name"
                            >
                                {share.name}
                            </h1>
                            <p className="mt-3 text-[#B3A69C]">
                                {share.author ? `by ${share.author} · ` : ''}
                                {share.prompts === 1
                                    ? '1 prompt'
                                    : `${share.prompts} prompts`}
                                {share.agent
                                    ? ` · built by ${share.agent}`
                                    : ''}
                            </p>

                            <figure className="mt-10 rounded-2xl bg-[#110E0C] p-6 ring-1 ring-[#2A2320] md:p-8">
                                <figcaption className="flex items-center gap-2 text-sm text-[#7D7068]">
                                    <MessageSquareText className="size-4" />
                                    The prompt
                                </figcaption>
                                <blockquote
                                    className="mt-3 font-display text-xl leading-snug font-bold tracking-[-0.01em] whitespace-pre-line md:text-2xl"
                                    data-test="share-prompt"
                                >
                                    “{share.prompt}”
                                </blockquote>
                            </figure>

                            <div className="mt-8 flex flex-wrap items-center gap-3">
                                <button
                                    type="button"
                                    onClick={remix}
                                    className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A2B] to-[#E8341C] px-5 py-3 font-semibold text-white shadow-[0_12px_32px_-10px_rgba(255,77,28,0.9),inset_0_1px_0_rgba(255,255,255,0.3)] transition hover:brightness-110 focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
                                    data-test="remix"
                                >
                                    <Shuffle className="size-4" />
                                    Remix this
                                </button>
                                {share.app_url && (
                                    <a
                                        href={share.app_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-2 rounded-xl px-5 py-3 font-semibold ring-1 ring-[#3A302B] transition hover:bg-[#1C1714]"
                                        data-test="open-app"
                                    >
                                        Open the app
                                        <ExternalLink className="size-4" />
                                    </a>
                                )}
                                {workspaceUrl && (
                                    <Link
                                        href={workspaceUrl}
                                        className="inline-flex items-center gap-2 rounded-xl px-4 py-3 text-sm text-[#B3A69C] hover:text-white"
                                        data-test="edit-share"
                                    >
                                        <Pencil className="size-4" />
                                        This is yours. Change it from Share in
                                        the workspace.
                                    </Link>
                                )}
                            </div>

                            <div
                                className="mt-12 overflow-hidden rounded-2xl bg-[#1A1512] shadow-[0_30px_80px_rgba(0,0,0,0.6),0_0_90px_rgba(255,154,92,0.18)] ring-1 ring-white/10"
                                data-test="share-screenshot"
                            >
                                <div className="flex h-10 items-center gap-2 bg-[#221B17] px-4">
                                    <span className="size-3 rounded-full bg-white/15" />
                                    <span className="size-3 rounded-full bg-white/15" />
                                    <span className="size-3 rounded-full bg-white/15" />
                                    <span className="mx-auto max-w-[60%] truncate rounded-md bg-black/30 px-3 py-1 text-xs text-[#7D7068]">
                                        {host}
                                    </span>
                                </div>
                                {share.screenshot_url ? (
                                    <img
                                        src={share.screenshot_url}
                                        alt={`Screenshot of ${share.name}`}
                                        className="block w-full"
                                    />
                                ) : (
                                    <div className="flex aspect-[1280/800] items-center justify-center text-[#7D7068]">
                                        The screenshot is on its way.
                                    </div>
                                )}
                            </div>
                        </div>
                    </section>

                    <section className="border-t border-[#2A2320]">
                        <div className="mx-auto flex max-w-5xl flex-col items-start gap-6 px-6 py-16 md:flex-row md:items-center">
                            <div className="flex-1">
                                <h2 className="font-display text-3xl font-extrabold tracking-[-0.03em]">
                                    Describe an app. Watch it get built.
                                </h2>
                                <p className="mt-2 text-[#B3A69C]">
                                    Real, tested code from one prompt, with your
                                    own AI plan. Deploy it anywhere.
                                </p>
                            </div>
                            {isLoggedIn ? (
                                <button
                                    type="button"
                                    onClick={remix}
                                    className="rounded-xl bg-[#F5EFEA] px-5 py-3 font-semibold text-[#0A0807] hover:bg-white"
                                >
                                    Start from this prompt
                                </button>
                            ) : (
                                <Link
                                    href={register()}
                                    className="rounded-xl bg-[#F5EFEA] px-5 py-3 font-semibold text-[#0A0807] hover:bg-white"
                                >
                                    Start building free
                                </Link>
                            )}
                        </div>
                    </section>
                </main>

                <SiteFooter />
            </div>
        </>
    );
}
