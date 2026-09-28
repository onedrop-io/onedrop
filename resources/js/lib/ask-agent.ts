import { router } from '@inertiajs/react';
import { jsonRequest } from '@/lib/json-request';

/**
 * Send the agent a request from a Tools panel (it lands in the chat, queued if the agent is busy),
 * then refresh the workspace's chat so it shows the message and starts following the run.
 */
export async function askAgent(
    url: string,
    body: unknown = {},
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'POST',
): Promise<{ queued: boolean }> {
    const result = await jsonRequest<{ queued: boolean }>(url, body, method);

    router.reload({ only: ['project', 'messages', 'queued'] });

    return result;
}
