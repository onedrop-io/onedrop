import { Head, Link, usePage } from '@inertiajs/react';
import { Check, KeyRound, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { SiteFooter } from '@/components/home/site-footer';
import { SiteHeader } from '@/components/home/site-header';
import { DOCUMENTATION_URL } from '@/lib/links';
import { dashboard, register } from '@/routes';

type Billing = 'monthly' | 'annual';

type Plan = {
    id: string;
    name: string;
    tagline: string;
    /** Price per month in US dollars, or null for free plans. */
    price: { monthly: number; annual: number } | null;
    priceNote: string;
    /** Monthly AI credits in US dollars, used only by people who haven't connected their own AI. */
    credits: number | null;
    features: string[];
    cta: { label: string; href: 'register' | 'docs' };
    isFeatured?: boolean;
};

const PLANS: Plan[] = [
    {
        id: 'self-hosted',
        name: 'Self-hosted',
        tagline: 'Run it yourself, free forever.',
        price: null,
        priceNote: 'Source available, no limits',
        credits: null,
        features: [
            'Unlimited builders, projects, and apps',
            'Your own AI plan or key',
            'Your laptop, any Ubuntu server, or AWS',
            'Every feature, nothing held back',
            'Community support on GitHub',
        ],
        cta: { label: 'Read the install guide', href: 'docs' },
    },
    {
        id: 'solo',
        name: 'Solo',
        tagline: 'For one person shipping real apps.',
        price: { monthly: 20, annual: 16 },
        priceNote: '1 builder',
        credits: 10,
        features: [
            'Unlimited projects',
            '3 always-on apps',
            'Private and public share links',
            'Your own AI with no markup',
        ],
        cta: { label: 'Start building', href: 'register' },
    },
    {
        id: 'team',
        name: 'Team',
        tagline: 'For a team building its own tools.',
        price: { monthly: 99, annual: 79 },
        priceNote: 'Up to 10 builders',
        credits: 50,
        features: [
            'Everything in Solo',
            '20 always-on apps',
            'Groups, roles, and invite links',
            'Shared AI credits for the whole team',
        ],
        cta: { label: 'Start building', href: 'register' },
        isFeatured: true,
    },
    {
        id: 'business',
        name: 'Business',
        tagline: 'For a company running on its apps.',
        price: { monthly: 299, annual: 239 },
        priceNote: 'Up to 50 builders',
        credits: 200,
        features: [
            'Everything in Team',
            '50 always-on apps',
            'Sign in with Google, GitHub, or Microsoft',
            'Priority support',
        ],
        cta: { label: 'Start building', href: 'register' },
    },
];

const PROMISES = [
    {
        title: 'No markup on your AI',
        body: 'Connect your Claude or ChatGPT plan or an API key and it costs you exactly what your provider charges. We never touch it.',
    },
    {
        title: 'Hosting never eats your credits',
        body: 'Your apps’ hosting is part of the plan. AI credits are only for building.',
    },
    {
        title: 'Your apps never pause',
        body: 'Run out of credits and building waits for a top-up. Published apps keep running.',
    },
    {
        title: 'Flat prices, not per seat',
        body: 'Add a teammate without doing math. Anyone can open a published app for free.',
    },
];

const COMPARISON = [
    {
        topic: 'AI usage',
        us: 'Your own plan or key, no markup',
        them: 'Their credits, marked up',
    },
    {
        topic: 'When credits run out',
        us: 'Your apps keep running',
        them: 'Building stops, sometimes your apps too',
    },
    {
        topic: 'Hosting',
        us: 'Included in the plan',
        them: 'Metered, or paid from build credits',
    },
    {
        topic: 'Teams',
        us: 'One flat price for up to 50 builders',
        them: 'Per seat',
    },
    {
        topic: 'Self-hosting',
        us: 'Free, forever',
        them: 'Not an option',
    },
];

const FAQS = [
    {
        question: 'How do the included AI credits work?',
        answer: 'Anyone on your plan who hasn’t connected their own AI builds with the plan’s credits, which cover model usage at provider rates. People who connect a Claude or ChatGPT plan or an API key never use credits. Unused credits roll over for one month, and you can top up any time at provider rates.',
    },
    {
        question: 'Can I bring my own AI on every plan?',
        answer: 'Yes, including the free self-hosted plan. Use your Claude Pro or Max plan or your ChatGPT Plus or Pro plan, or paste a key from Anthropic, OpenAI, Google, OpenRouter, or Ollama. You pay your provider directly, and we add nothing on top.',
    },
    {
        question: 'Who counts as a builder?',
        answer: 'Anyone who creates or changes apps. People who only use your published apps are free and unlimited.',
    },
    {
        question: 'What’s an always-on app?',
        answer: 'A published app we keep running around the clock, with its own link. You can build as many projects as you like; the limit is only on how many stay live at once.',
    },
    {
        question: 'Is self-hosting really free?',
        answer: 'Yes. OneDrop is source available and free to self-host. Run it on your laptop, any Ubuntu server, or AWS with every feature and no limits. You only pay for your servers and your AI.',
    },
    {
        question: 'Can I leave whenever I want?',
        answer: 'Yes. Cancel any time and keep all your code. It’s standard code, so you can move it to a self-hosted OneDrop or anywhere else.',
    },
];

function PlanPrice({ plan, billing }: { plan: Plan; billing: Billing }) {
    if (plan.price === null) {
        return (
            <p className="flex items-baseline gap-2">
                <span className="font-display text-5xl font-extrabold tracking-[-0.03em]">
                    Free
                </span>
            </p>
        );
    }

    const amount = plan.price[billing];

    return (
        <p className="flex items-baseline gap-2">
            <span
                data-test={`${plan.id}-price`}
                className="font-display text-5xl font-extrabold tracking-[-0.03em]"
            >
                ${amount}
            </span>
            <span className="text-sm text-[#7D7068]">/month</span>
            {billing === 'annual' && (
                <span className="text-sm text-[#7D7068] line-through">
                    ${plan.price.monthly}
                </span>
            )}
        </p>
    );
}

function PlanCard({
    plan,
    billing,
    isLoggedIn,
}: {
    plan: Plan;
    billing: Billing;
    isLoggedIn: boolean;
}) {
    const ctaClass = plan.isFeatured
        ? 'bg-gradient-to-b from-[#FF6A2B] to-[#E8341C] text-white shadow-[0_12px_32px_-10px_rgba(255,77,28,0.9),inset_0_1px_0_rgba(255,255,255,0.3)] hover:brightness-110'
        : 'text-[#F5EFEA] ring-1 ring-[#3A302B] hover:bg-[#1C1714]';
    const ctaBase =
        'mt-8 inline-flex items-center justify-center rounded-xl px-5 py-3 font-semibold transition focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none';

    return (
        <li
            data-test={`plan-${plan.id}`}
            className={`relative flex flex-col rounded-2xl p-7 ${
                plan.isFeatured
                    ? 'bg-gradient-to-b from-[#2A1710] to-[#151110] shadow-[0_0_60px_-20px_rgba(255,77,28,0.7)] ring-2 ring-[#FF4D1C]/70'
                    : 'bg-[#110E0C] ring-1 ring-[#2A2320]'
            }`}
        >
            {plan.isFeatured && (
                <span className="absolute -top-3 left-7 rounded-full bg-[#FF4D1C] px-3 py-1 text-xs font-semibold text-white">
                    Most popular
                </span>
            )}
            <h2 className="font-display text-xl font-bold">{plan.name}</h2>
            <p className="mt-1 text-sm text-[#B3A69C]">{plan.tagline}</p>
            <div className="mt-6">
                <PlanPrice plan={plan} billing={billing} />
                <p className="mt-1 text-sm text-[#7D7068]">
                    {plan.priceNote}
                    {plan.price !== null &&
                        billing === 'annual' &&
                        ' · billed yearly'}
                </p>
            </div>
            <p className="mt-6 flex items-start gap-2.5 rounded-xl bg-white/[0.03] p-3 text-sm ring-1 ring-white/5">
                {plan.credits === null ? (
                    <>
                        <KeyRound
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-[#FF9A5C]"
                        />
                        <span>Bring your own AI</span>
                    </>
                ) : (
                    <>
                        <Sparkles
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-[#FF9A5C]"
                        />
                        <span>
                            <span className="font-semibold">
                                ${plan.credits} in AI credits
                            </span>{' '}
                            <span className="text-[#B3A69C]">
                                a month, or bring your own
                            </span>
                        </span>
                    </>
                )}
            </p>
            <ul className="mt-6 space-y-3 text-sm">
                {plan.features.map((feature) => (
                    <li key={feature} className="flex items-start gap-2.5">
                        <Check
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-[#FF9A5C]"
                        />
                        <span className="text-[#E6DDD6]">{feature}</span>
                    </li>
                ))}
            </ul>
            <div className="mt-auto flex flex-col">
                {plan.cta.href === 'docs' ? (
                    <a
                        href={DOCUMENTATION_URL}
                        data-test={`${plan.id}-cta`}
                        className={`${ctaBase} ${ctaClass}`}
                    >
                        {plan.cta.label}
                    </a>
                ) : (
                    <Link
                        href={isLoggedIn ? dashboard() : register()}
                        data-test={`${plan.id}-cta`}
                        className={`${ctaBase} ${ctaClass}`}
                    >
                        {isLoggedIn ? 'Open your dashboard' : plan.cta.label}
                    </Link>
                )}
            </div>
        </li>
    );
}

function BillingToggle({
    billing,
    onChange,
}: {
    billing: Billing;
    onChange: (billing: Billing) => void;
}) {
    const options: { value: Billing; label: string }[] = [
        { value: 'monthly', label: 'Monthly' },
        { value: 'annual', label: 'Yearly' },
    ];

    return (
        <div
            role="radiogroup"
            aria-label="Billing period"
            className="inline-flex rounded-xl bg-[#151110] p-1 ring-1 ring-[#2A2320]"
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={billing === option.value}
                    onClick={() => onChange(option.value)}
                    className={`rounded-lg px-4 py-2 text-sm font-medium transition ${
                        billing === option.value
                            ? 'bg-[#F5EFEA] text-[#0A0807]'
                            : 'text-[#B3A69C] hover:text-white'
                    }`}
                >
                    {option.label}
                    {option.value === 'annual' && (
                        <span
                            className={`ml-2 text-xs ${
                                billing === 'annual'
                                    ? 'text-[#E8341C]'
                                    : 'text-[#FF9A5C]'
                            }`}
                        >
                            Save 20%
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}

export default function Pricing() {
    const { auth } = usePage().props;
    const isLoggedIn = Boolean(auth.user);
    const [billing, setBilling] = useState<Billing>('annual');

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
            <Head title="Pricing" />

            <div className="min-h-screen bg-[#0A0807] font-sans text-[#F5EFEA] antialiased [color-scheme:dark]">
                <SiteHeader isLoggedIn={isLoggedIn} />

                <main>
                    <section className="relative overflow-hidden">
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-x-0 -top-40 h-[480px] bg-[radial-gradient(50%_60%_at_50%_0%,rgba(255,77,28,0.28),transparent_70%)]"
                        />
                        <div className="relative mx-auto max-w-6xl px-6 pt-16 pb-12 text-center md:pt-24">
                            <h1 className="mx-auto max-w-3xl bg-gradient-to-b from-white via-white to-[#FFC7A8] bg-clip-text pb-2 font-display text-5xl leading-[0.95] font-extrabold tracking-[-0.035em] text-balance text-transparent sm:text-6xl">
                                Bring your own AI. We never mark it up.
                            </h1>
                            <p className="mx-auto mt-6 max-w-xl text-lg leading-relaxed text-[#B3A69C]">
                                Pay for your team and your hosting, not for
                                tokens. No AI of your own? Paid plans come with
                                credits.
                            </p>
                            <div className="mt-10">
                                <BillingToggle
                                    billing={billing}
                                    onChange={setBilling}
                                />
                            </div>
                        </div>
                    </section>

                    <section className="mx-auto max-w-6xl px-6 pb-24">
                        <ul className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                            {PLANS.map((plan) => (
                                <PlanCard
                                    key={plan.id}
                                    plan={plan}
                                    billing={billing}
                                    isLoggedIn={isLoggedIn}
                                />
                            ))}
                        </ul>
                        <p className="mt-8 text-center text-sm text-[#7D7068]">
                            Prices in US dollars. Need more than 50 builders?
                            Self-host with no limits.
                        </p>
                    </section>

                    <section className="border-y border-[#2A2320] bg-[#100D0B]">
                        <div className="mx-auto max-w-6xl px-6 py-24">
                            <h2 className="max-w-2xl font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                                No surprise bills. Ever.
                            </h2>
                            <dl className="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                                {PROMISES.map((promise) => (
                                    <div
                                        key={promise.title}
                                        className="border-l-2 border-[#FF4D1C] pl-5"
                                    >
                                        <dt className="font-display text-lg font-bold">
                                            {promise.title}
                                        </dt>
                                        <dd className="mt-2 leading-relaxed text-[#B3A69C]">
                                            {promise.body}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </section>

                    <section className="mx-auto grid max-w-6xl gap-14 px-6 py-24 lg:grid-cols-2 lg:items-center">
                        <div>
                            <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                                Other builders resell you AI. We don’t.
                            </h2>
                            <p className="mt-5 text-lg leading-relaxed text-[#B3A69C]">
                                Most app builders make their money on AI
                                credits, so every fix and every retry costs you.
                                OneDrop charges for hosting and teams, and your
                                AI runs at cost.
                            </p>
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
                                            OneDrop
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
                    </section>

                    <section
                        id="faq"
                        className="mx-auto max-w-3xl scroll-mt-20 border-t border-[#2A2320] px-6 py-24"
                    >
                        <h2 className="font-display text-3xl font-bold tracking-[-0.02em] sm:text-4xl">
                            Pricing questions
                        </h2>
                        <dl className="mt-10 divide-y divide-[#2A2320]">
                            {FAQS.map((faq) => (
                                <div key={faq.question} className="py-6">
                                    <dt className="font-display text-lg font-bold">
                                        {faq.question}
                                    </dt>
                                    <dd className="mt-2 leading-relaxed text-[#B3A69C]">
                                        {faq.answer}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                </main>

                <SiteFooter />
            </div>
        </>
    );
}
