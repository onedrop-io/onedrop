import { BrainIcon, ChevronRightIcon, XIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ProjectMessageController from '@/actions/App/Http/Controllers/ProjectMessageController';
import { ChatMarkdown } from '@/components/markdown';
import { Spinner } from '@/components/ui/spinner';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import PatchView from '@/components/workspace/patch-view';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import type {
    AgentStepDetail,
    AgentStream,
    ChatMessage,
} from '@/types/projects';

/*
 * The agent's steps in the chat (AGT-017): each tool call and thinking is one line, which expands to what it did.
 * Adapted from AI Elements' Tool and Reasoning (https://elements.ai-sdk.dev), for our steps and their details.
 */

/**
 * A step's line: a dot, or a spinner while it runs, and a cross when it failed. `live` pulses the dot of the agent's
 * current step (for steps that don't say whether they're running, like older ones).
 */
function StepLine({
    message,
    text,
    running,
    live,
    expandable = false,
}: {
    message: ChatMessage;
    text: string;
    running: boolean;
    live: boolean;
    expandable?: boolean;
}) {
    const failed = message.step?.status === 'failed';

    return (
        <span className="flex min-w-0 items-center gap-2">
            <span className="flex size-3 shrink-0 items-center justify-center">
                {running ? (
                    <Spinner
                        className="size-3 text-emerald-500"
                        aria-label="Running"
                    />
                ) : failed ? (
                    <XIcon
                        className="size-3 text-destructive"
                        aria-label="Failed"
                    />
                ) : message.step?.kind === 'thinking' ? (
                    <BrainIcon className="size-3" />
                ) : (
                    <span
                        className={cn(
                            'size-1.5 rounded-full',
                            live
                                ? 'animate-pulse bg-emerald-500'
                                : 'bg-current',
                        )}
                    />
                )}
            </span>
            <span className={cn('min-w-0', expandable && 'truncate')}>
                {text}
            </span>
            {expandable && (
                <ChevronRightIcon className="size-3.5 shrink-0 opacity-50 transition group-hover:opacity-100 group-focus-visible:opacity-100 group-data-[state=open]:rotate-90 group-data-[state=open]:opacity-100" />
            )}
        </span>
    );
}

/**
 * An activity line in the chat. In Advanced mode a step with details expands to them, fetched the first time it's
 * opened; in Simple mode (PRJ-013) steps stay plain words.
 */
export function AgentStep({
    projectId,
    message,
    live,
    working,
    simple,
}: {
    projectId: number;
    message: ChatMessage;
    /** The agent's current step. */
    live: boolean;
    /** The agent is still at work, so a running step is still running. */
    working: boolean;
    simple: boolean;
}) {
    const text = simple ? (message.plain ?? message.content) : message.content;
    const running = working && message.step?.status === 'running';
    const [detail, setDetail] = useState<AgentStepDetail | null>(null);
    const [failed, setFailed] = useState(false);
    // A running step's details grow, so they're fetched again once it finishes.
    const fetchedWhile = useRef<string | null>(null);
    const [open, setOpen] = useState(false);
    const status = message.step?.status ?? 'done';

    useEffect(() => {
        if (!open || fetchedWhile.current === status) {
            return;
        }

        fetchedWhile.current = status;
        jsonRequest<AgentStepDetail>(
            ProjectMessageController.detail.url({
                project: projectId,
                message: message.id,
            }),
        )
            .then((loaded) => {
                setDetail(loaded);
                setFailed(false);
            })
            .catch(() => setFailed(true));
    }, [open, status, projectId, message.id]);

    if (simple || !message.step?.expandable) {
        return (
            <p
                className="flex items-center gap-2 text-sm text-muted-foreground"
                data-test="message-activity"
            >
                <StepLine
                    message={message}
                    text={text}
                    running={running}
                    live={live}
                />
            </p>
        );
    }

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="text-sm text-muted-foreground"
            data-test="message-activity"
        >
            <CollapsibleTrigger
                className="group flex max-w-full items-center rounded text-left hover:text-foreground focus-visible:text-foreground focus-visible:outline-none data-[state=open]:text-foreground"
                data-test="step-toggle"
            >
                <StepLine
                    message={message}
                    text={text}
                    running={running}
                    live={live}
                    expandable
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0">
                <div className="mt-2 ml-5" data-test="step-detail">
                    {detail ? (
                        <StepDetail
                            detail={detail}
                            thinking={message.step.kind === 'thinking'}
                        />
                    ) : failed ? (
                        <p className="text-xs">
                            Couldn't load this step's details.
                        </p>
                    ) : (
                        <Spinner className="size-3" />
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * What a step did: the thinking; or the command, what it looked for, the change it made as a diff, and its output or
 * error.
 */
function StepDetail({
    detail,
    thinking,
}: {
    detail: AgentStepDetail;
    thinking: boolean;
}) {
    if (thinking) {
        return (
            <ChatMarkdown
                content={detail.text ?? ''}
                className="border-l-2 pl-3 text-xs leading-relaxed"
            />
        );
    }

    return (
        <div className="space-y-2 text-xs">
            {detail.command && (
                <pre className="overflow-x-auto rounded-md bg-muted px-3 py-2 font-mono whitespace-pre-wrap text-foreground">
                    <span className="text-muted-foreground select-none">
                        ${' '}
                    </span>
                    {detail.command}
                </pre>
            )}
            {detail.query && (
                <p>
                    Looked for{' '}
                    <code className="rounded bg-muted px-1 py-0.5 font-mono text-foreground">
                        {detail.query}
                    </code>
                </p>
            )}
            {detail.diff && (
                <PatchView
                    patch={detail.diff}
                    truncated={false}
                    path={detail.path}
                    className="max-h-80 rounded-md border"
                />
            )}
            {detail.output && (
                <pre
                    className="max-h-80 overflow-auto rounded-md border bg-muted/30 px-3 py-2 font-mono whitespace-pre-wrap text-foreground"
                    data-test="step-output"
                >
                    {detail.output}
                </pre>
            )}
            {detail.error && (
                <pre className="max-h-80 overflow-auto rounded-md bg-destructive/10 px-3 py-2 font-mono whitespace-pre-wrap text-destructive">
                    {detail.error}
                </pre>
            )}
        </div>
    );
}

/**
 * The reply or thinking the agent is writing right now. A reply renders as it will once saved; thinking shows as
 * "Thinking…", open on its text while it's written in Advanced mode (it closes once saved, as a step).
 */
export function StreamingBlock({
    stream,
    simple,
}: {
    stream: AgentStream;
    simple: boolean;
}) {
    const box = useRef<HTMLDivElement>(null);

    // Thinking shows its newest lines.
    useEffect(() => {
        box.current?.scrollTo({ top: box.current.scrollHeight });
    }, [stream.text]);

    if (stream.kind === 'text') {
        return (
            <ChatMarkdown
                content={stream.text}
                streaming
                className="text-sm leading-relaxed"
                data-test="message-streaming"
            />
        );
    }

    return (
        <div
            className="text-sm text-muted-foreground"
            data-test="thinking-streaming"
        >
            <p className="flex items-center gap-2">
                <BrainIcon className="size-3 shrink-0" />
                <span className="shimmer-text">Thinking…</span>
            </p>
            {!simple && (
                <div
                    ref={box}
                    className="mt-2 ml-5 max-h-32 overflow-hidden border-l-2 pl-3 text-xs leading-relaxed"
                >
                    <ChatMarkdown content={stream.text} streaming />
                </div>
            )}
        </div>
    );
}
