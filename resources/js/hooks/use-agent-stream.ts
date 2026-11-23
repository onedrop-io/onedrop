import { useCallback, useEffect, useRef, useState } from 'react';
import type { AgentStream, ChatMessage } from '@/types/projects';

/** A piece of a reply or thinking, pushed as the agent writes it (the `AgentStreamed` event). */
export type AgentStreamedPayload = {
    /** "main", or "task-<id>". */
    chat: string;
    block: string;
    kind: AgentStream['kind'];
    seq: number;
    delta: string;
};

/**
 * The reply or thinking the agent is writing in this chat (AGT-017): the text so far from the page's live data
 * (`initial`, also how pages without live updates follow along), plus pieces pushed as they're written (`onStreamed`).
 * A missed piece calls `reload` to catch up from the live data. It stops showing once its message is saved, or the
 * agent stops working.
 */
export function useAgentStream(
    chat: string | null,
    initial: AgentStream | null,
    messages: ChatMessage[],
    working: boolean,
    reload: () => void,
): {
    stream: AgentStream | null;
    onStreamed: (payload: AgentStreamedPayload) => void;
} {
    const [stream, setStream] = useState<AgentStream | null>(initial);
    const latest = useRef(stream);

    useEffect(() => {
        latest.current = stream;
    }, [stream]);

    // Another chat starts from its own text so far.
    useEffect(() => {
        setStream(initial);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chat]);

    // The live data caught up: keep whichever is further along.
    useEffect(() => {
        if (
            initial &&
            (latest.current?.block !== initial.block ||
                initial.seq > latest.current.seq)
        ) {
            setStream(initial);
        }
    }, [initial]);

    const onStreamed = useCallback(
        (payload: AgentStreamedPayload) => {
            if (payload.chat !== chat) {
                return;
            }

            const current = latest.current;
            let next: AgentStream | null = null;

            if (current?.block === payload.block) {
                if (payload.seq <= current.seq) {
                    return;
                }

                if (payload.seq === current.seq + 1) {
                    next = {
                        ...current,
                        seq: payload.seq,
                        text: current.text + payload.delta,
                    };
                }
            } else if (payload.seq === 1) {
                next = {
                    block: payload.block,
                    kind: payload.kind,
                    seq: 1,
                    text: payload.delta,
                };
            }

            if (next === null) {
                reload();

                return;
            }

            latest.current = next;
            setStream(next);
        },
        [chat, reload],
    );

    const saved =
        stream !== null &&
        messages.some((message) => message.blocks.includes(stream.block));

    return {
        stream: stream && working && !saved ? stream : null,
        onStreamed,
    };
}
