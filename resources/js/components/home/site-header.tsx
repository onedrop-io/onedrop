import { Link } from '@inertiajs/react';
import { FEATURES } from '@/components/home/features';
import { GitHubMark } from '@/components/home/github-mark';
import { PoppingDrop } from '@/components/home/popping-drop';
import { ProductMenu } from '@/components/home/product-menu';
import { DOCUMENTATION_URL, REPOSITORY_URL } from '@/lib/links';
import { dashboard, login, pricing, register } from '@/routes';

/**
 * The marketing pages' sticky top bar: logo, Product menu, page links, Docs, GitHub,
 * and log in / sign up (or Dashboard when logged in).
 */
export function SiteHeader({ isLoggedIn }: { isLoggedIn: boolean }) {
    return (
        <div className="sticky top-0 z-50 border-b border-white/5 bg-[#0A0807]/70 backdrop-blur-lg">
            <header className="mx-auto flex max-w-6xl items-center gap-8 px-6 py-4">
                <Link
                    href="/"
                    className="flex items-center gap-2 font-display text-xl font-extrabold tracking-tight"
                >
                    <PoppingDrop />
                    OneDrop
                </Link>
                <nav className="hidden items-center gap-6 text-sm text-[#B3A69C] md:flex">
                    <ProductMenu features={FEATURES} />
                    <a href="/#how-it-works" className="hover:text-white">
                        How it works
                    </a>
                    <Link href={pricing()} className="hover:text-white">
                        Pricing
                    </Link>
                    <a href="/#desktop" className="hover:text-white">
                        Download
                    </a>
                    <a href="/#install" className="hover:text-white">
                        Self-host
                    </a>
                    <a href="/#faq" className="hover:text-white">
                        FAQ
                    </a>
                    <a href={DOCUMENTATION_URL} className="hover:text-white">
                        Docs
                    </a>
                </nav>
                <div className="ml-auto flex items-center gap-2 text-sm">
                    <a
                        href={REPOSITORY_URL}
                        aria-label="OneDrop on GitHub"
                        data-test="header-github"
                        className="hidden size-9 place-items-center rounded-lg text-[#B3A69C] hover:bg-white/5 hover:text-white sm:grid"
                    >
                        <GitHubMark className="size-5" />
                    </a>
                    {isLoggedIn ? (
                        <Link
                            href={dashboard()}
                            className="rounded-lg bg-[#F5EFEA] px-4 py-2 font-medium whitespace-nowrap text-[#0A0807] hover:bg-white"
                        >
                            Dashboard
                        </Link>
                    ) : (
                        <>
                            <Link
                                href={login()}
                                className="rounded-lg px-3 py-2 font-medium whitespace-nowrap text-[#B3A69C] hover:text-white"
                            >
                                Log in
                            </Link>
                            <Link
                                href={register()}
                                className="rounded-lg bg-[#F5EFEA] px-4 py-2 font-medium whitespace-nowrap text-[#0A0807] hover:bg-white"
                            >
                                Start building
                            </Link>
                        </>
                    )}
                </div>
            </header>
        </div>
    );
}
