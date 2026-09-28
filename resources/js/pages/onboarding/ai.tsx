import { Head, Link } from '@inertiajs/react';
import AgentConnections from '@/components/agent-connections';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { AgentConnection } from '@/types';

export default function OnboardingAi({
    connections,
}: {
    connections: AgentConnection[];
}) {
    return (
        <>
            <Head title="Set up your AI" />

            <div className="min-h-svh bg-background px-4 py-12">
                <div className="mx-auto flex max-w-2xl flex-col gap-8">
                    <div className="space-y-3">
                        <AppLogoIcon className="size-8 fill-current" />
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Set up your AI
                        </h1>
                        <p className="text-muted-foreground">
                            Your apps get built on your own AI plan or key: no
                            credits, no markup. Connect at least one to start
                            building. Credentials are encrypted and only used
                            inside your sandboxes.
                        </p>
                    </div>

                    <AgentConnections connections={connections} onboarding />

                    <div className="flex items-center justify-end gap-4">
                        {connections.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Connect an AI to continue
                            </p>
                        ) : (
                            <Button asChild data-test="onboarding-continue">
                                <Link href={dashboard()}>Continue</Link>
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
