import * as NavigationMenu from '@radix-ui/react-navigation-menu';
import type { LucideIcon } from 'lucide-react';
import { ArrowRight, ChevronDown, Play } from 'lucide-react';

type ProductMenuFeature = {
    id: string;
    icon: LucideIcon;
    name: string;
    summary: string;
};

const EXPLORE_LINKS = [
    { href: '/#how-it-works', label: 'How it works' },
    { href: '/#integrations', label: 'Integrations' },
    { href: '/#compare', label: 'Compared with hosted builders' },
    { href: '/#ways', label: 'Browser, desktop or self-hosted' },
    { href: '/#faq', label: 'FAQ' },
];

/**
 * The "Product" dropdown in the home page's top bar: every feature with a
 * one-line summary, plus links to the other parts of the page.
 */
export function ProductMenu({ features }: { features: ProductMenuFeature[] }) {
    return (
        <NavigationMenu.Root className="relative">
            <NavigationMenu.List className="flex items-center">
                <NavigationMenu.Item className="relative">
                    <NavigationMenu.Trigger
                        data-test="product-menu"
                        className="group flex items-center gap-1 outline-none hover:text-white focus-visible:text-white data-[state=open]:text-white"
                    >
                        Product
                        <ChevronDown
                            aria-hidden="true"
                            className="size-3.5 transition-transform duration-200 group-data-[state=open]:rotate-180"
                        />
                    </NavigationMenu.Trigger>
                    <NavigationMenu.Content className="absolute top-full -left-6 z-50 pt-4 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 data-[state=open]:slide-in-from-top-1">
                        <div className="grid w-[680px] grid-cols-[minmax(0,1fr)_200px] overflow-hidden rounded-2xl border border-[#2A2320] bg-[#110E0C] shadow-[0_24px_80px_-12px_rgba(0,0,0,0.8),0_0_0_1px_rgba(255,77,28,0.06)] backdrop-blur-xl">
                            <div className="p-3">
                                <p className="px-3 pt-2 pb-1 text-xs font-medium tracking-wide text-[#7D7068] uppercase">
                                    Features
                                </p>
                                <ul className="grid grid-cols-2 gap-1">
                                    {features.map((feature) => (
                                        <li key={feature.id}>
                                            <NavigationMenu.Link asChild>
                                                <a
                                                    href={`/#${feature.id}`}
                                                    className="group/item flex gap-3 rounded-xl p-3 outline-none hover:bg-white/[0.04] focus-visible:bg-white/[0.06]"
                                                >
                                                    <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-gradient-to-b from-[#FF4D1C]/30 to-[#FF4D1C]/10 ring-1 ring-[#FF4D1C]/30 transition-shadow group-hover/item:shadow-[0_0_18px_rgba(255,77,28,0.45)]">
                                                        <feature.icon
                                                            aria-hidden="true"
                                                            className="size-[18px] text-[#FFB27A]"
                                                        />
                                                    </span>
                                                    <span>
                                                        <span className="block font-medium text-[#F5EFEA]">
                                                            {feature.name}
                                                        </span>
                                                        <span className="mt-0.5 block text-[13px] leading-snug text-[#B3A69C]">
                                                            {feature.summary}
                                                        </span>
                                                    </span>
                                                </a>
                                            </NavigationMenu.Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <div className="flex flex-col border-l border-[#2A2320] bg-[#0D0A09] p-3">
                                <NavigationMenu.Link asChild>
                                    <a
                                        href="/#demo"
                                        className="group/demo relative overflow-hidden rounded-xl border border-[#3A302B] bg-[radial-gradient(120%_90%_at_100%_0%,rgba(255,77,28,0.35),transparent_60%)] p-4 outline-none hover:border-[#FF4D1C]/50 focus-visible:border-[#FF4D1C]/70"
                                    >
                                        <span className="grid size-9 place-items-center rounded-full bg-[#FF4D1C] text-white shadow-[0_0_24px_rgba(255,77,28,0.6)]">
                                            <Play
                                                aria-hidden="true"
                                                className="size-4 fill-current"
                                            />
                                        </span>
                                        <span className="mt-4 block font-display font-bold text-[#F5EFEA]">
                                            Watch the demo
                                        </span>
                                        <span className="mt-1 block text-[13px] leading-snug text-[#B3A69C]">
                                            Idea to link in seconds.
                                        </span>
                                    </a>
                                </NavigationMenu.Link>
                                <p className="px-3 pt-5 pb-1 text-xs font-medium tracking-wide text-[#7D7068] uppercase">
                                    Explore
                                </p>
                                <ul>
                                    {EXPLORE_LINKS.map((link) => (
                                        <li key={link.href}>
                                            <NavigationMenu.Link asChild>
                                                <a
                                                    href={link.href}
                                                    className="group/link flex items-center justify-between rounded-lg px-3 py-2 text-[#B3A69C] outline-none hover:bg-white/[0.04] hover:text-white focus-visible:bg-white/[0.06] focus-visible:text-white"
                                                >
                                                    {link.label}
                                                    <ArrowRight
                                                        aria-hidden="true"
                                                        className="size-3.5 -translate-x-1 opacity-0 transition group-hover/link:translate-x-0 group-hover/link:opacity-100"
                                                    />
                                                </a>
                                            </NavigationMenu.Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    </NavigationMenu.Content>
                </NavigationMenu.Item>
            </NavigationMenu.List>
        </NavigationMenu.Root>
    );
}
