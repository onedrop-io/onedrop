import { Link } from '@inertiajs/react';
import { DropMark } from '@/components/home/drop-mark';
import { DOCUMENTATION_URL, REPOSITORY_URL } from '@/lib/links';
import { pricing } from '@/routes';

/** The marketing pages' footer. */
export function SiteFooter() {
    return (
        <footer className="border-t border-[#2A2320]">
            <div className="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-8 text-sm text-[#B3A69C] sm:flex-row sm:items-center">
                <span className="flex items-center gap-2 font-display font-bold text-[#F5EFEA]">
                    <DropMark className="size-4 text-[#FF9A5C]" />
                    OneDrop
                </span>
                <span>Vibe-code it. Ship it anywhere.</span>
                <Link href={pricing()} className="hover:text-white sm:ml-auto">
                    Pricing
                </Link>
                <a href={DOCUMENTATION_URL} className="hover:text-white">
                    Docs
                </a>
                <a href={REPOSITORY_URL} className="hover:text-white">
                    Source on GitHub
                </a>
            </div>
        </footer>
    );
}
