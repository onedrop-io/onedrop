import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { useState } from 'react';
import {
    ProviderCard,
    ProviderLogo,
    providers,
} from '@/components/agent-connections';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { AgentConnection, AgentProvider } from '@/types';

export default function OnboardingAi({
    connections,
}: {
    connections: AgentConnection[];
}) {
    const [chosen, setChosen] = useState<AgentProvider | null>(null);
    const provider = providers.find((p) => p.id === chosen);

    return (
        <>
            <Head title="Set up your AI" />

            <div className="min-h-svh bg-background px-4 py-12">
                <div className="mx-auto flex max-w-2xl flex-col gap-8">
                    <div className="space-y-3">
                        <AppLogoIcon className="size-8 fill-current" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {provider
                                ? `Connect ${provider.name}`
                                : 'Which AI do you use?'}
                        </h1>
                        <p className="text-muted-foreground">
                            Your apps get built on your own AI plan: no credits,
                            no markup. Credentials are encrypted and only used
                            inside your sandboxes.
                        </p>
                    </div>

                    {provider ? (
                        <div className="space-y-4">
                            <ProviderCard
                                provider={provider}
                                connection={connections.find(
                                    (c) => c.provider === provider.id,
                                )}
                                showDefaultToggle={false}
                                onboarding
                            />
                            <button
                                type="button"
                                onClick={() => setChosen(null)}
                                className="inline-flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline"
                                data-test="choose-another-ai"
                            >
                                <ArrowLeft className="size-3.5" />
                                Choose a different AI
                            </button>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                            {providers.map((p) => {
                                const connected = connections.some(
                                    (c) => c.provider === p.id,
                                );

                                return (
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() => setChosen(p.id)}
                                        className="relative flex flex-col items-center gap-3 rounded-xl border border-sidebar-border/70 p-4 text-center transition hover:-translate-y-0.5 hover:border-foreground/30 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:border-sidebar-border"
                                        data-test={`choose-${p.id}`}
                                    >
                                        {connected && (
                                            <Check
                                                className="absolute top-2 right-2 size-4 text-green-600"
                                                aria-label="Connected"
                                            />
                                        )}
                                        <ProviderLogo
                                            provider={p}
                                            className="size-12 rounded-xl"
                                        />
                                        <span className="space-y-0.5">
                                            <span className="block font-medium">
                                                {p.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {p.hint}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    {connections.length > 0 && (
                        <div className="flex justify-end">
                            <Button asChild data-test="onboarding-continue">
                                <Link href={dashboard()}>Continue</Link>
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
