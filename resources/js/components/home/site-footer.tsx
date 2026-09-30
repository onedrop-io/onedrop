import { Link } from '@inertiajs/react';
import { DropMark } from '@/components/home/drop-mark';
import { GitHubMark } from '@/components/home/github-mark';
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
                <a
                    href={REPOSITORY_URL}
                    className="flex items-center gap-1.5 hover:text-white"
                >
                    <GitHubMark className="size-4" />
                    Source on GitHub
                </a>
            </div>
            <p className="mx-auto max-w-6xl px-6 pb-6 text-xs text-[#7D7068]">
                Planet maps by{' '}
                <a
                    href="https://www.solarsystemscope.com/textures/"
                    className="underline-offset-2 hover:text-[#B3A69C] hover:underline"
                >
                    Solar System Scope
                </a>
                , resized, under{' '}
                <a
                    href="https://creativecommons.org/licenses/by/4.0/"
                    className="underline-offset-2 hover:text-[#B3A69C] hover:underline"
                >
                    CC BY 4.0
                </a>
                . Moon maps by{' '}
                <a
                    href="https://svs.gsfc.nasa.gov/4720"
                    className="underline-offset-2 hover:text-[#B3A69C] hover:underline"
                >
                    NASA's Scientific Visualization Studio
                </a>
                . Hurricane Isabel photo by{' '}
                <a
                    href="https://science.nasa.gov/earth/earth-observatory/hurricane-isabel-12116/"
                    className="underline-offset-2 hover:text-[#B3A69C] hover:underline"
                >
                    Jeff Schmaltz, MODIS Land Rapid Response Team, NASA GSFC
                </a>
                . Apollo 11 moonwalk footage by{' '}
                <a
                    href="https://commons.wikimedia.org/wiki/File:Apollo_11_Moonwalk_Montage.webm"
                    className="underline-offset-2 hover:text-[#B3A69C] hover:underline"
                >
                    NASA
                </a>
                . Voyager and Pioneer models and the Golden Record photo by{' '}
                <a
                    href="https://science.nasa.gov/3d-resources/"
                    className="underline-offset-2 hover:text-[#B3A69C] hover:underline"
                >
                    NASA
                </a>
                ; Pioneer plaque drawing by{' '}
                <a
                    href="https://commons.wikimedia.org/wiki/File:Pioneer_plaque.svg"
                    className="underline-offset-2 hover:text-[#B3A69C] hover:underline"
                >
                    Oona Räisänen
                </a>
                , from NASA's photo. Laniakea's galaxies from the{' '}
                <a
                    href="https://doi.org/10.26093/cds/vizier.21990026"
                    className="underline-offset-2 hover:text-[#B3A69C] hover:underline"
                >
                    2MASS Redshift Survey
                </a>{' '}
                (Huchra et al. 2012), via VizieR, CDS, Strasbourg.
            </p>
        </footer>
    );
}
