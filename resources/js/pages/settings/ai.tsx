import { Head } from '@inertiajs/react';
import AgentConnections from '@/components/agent-connections';
import Heading from '@/components/heading';
import { index } from '@/routes/agent-connections';
import type { AgentConnection } from '@/types';

export default function AiSettings({
    connections,
}: {
    connections: AgentConnection[];
}) {
    return (
        <>
            <Head title="AI settings" />

            <h1 className="sr-only">AI settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="AI"
                    description="The AI accounts your agent runs on. Keys are encrypted and only used inside your sandboxes."
                />
                <AgentConnections connections={connections} />
            </div>
        </>
    );
}

AiSettings.layout = {
    breadcrumbs: [{ title: 'AI settings', href: index() }],
};
