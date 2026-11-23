// Turns each agent's output into the events the platform reads (docker/sandbox/forwarder.mjs sends them), AGT-017:
// replies and thinking as they're written (`onedrop.delta`), and each tool call with a little of what it did (the
// command and its output, a diff). Claude Code's events keep their own shape, compacted. OpenCode's server events and
// Codex's app-server notifications become the events `opencode run --format json` and `codex exec --json` print,
// which the platform has always read, so it works with sandboxes on either version of this file.
// No I/O here, so it can be tested on its own (tests/Unit/AgentStreamTest.php).

/** How much of each kind of detail is kept. Commands' output keeps its end, where errors are. */
export const LIMITS = {
    command: 2000,
    output: 8000,
    diff: 20000,
    thinking: 20000,
};

/** The longest stretch of a stream held back in case a secret is being written (see StreamRedactor). */
const MAX_HOLDBACK = 64;

/** Text cut to about `max` characters: a quarter from its start and the rest from its end. */
export function clip(text, max) {
    if (typeof text !== 'string') {
        return undefined;
    }

    if (text.length <= max) {
        return text;
    }

    const head = Math.floor(max / 4);
    const tail = max - head;
    const hidden = text.slice(head, text.length - tail).split('\n').length - 1;

    return `${text.slice(0, head)}\n… ${hidden > 0 ? `${hidden} lines` : 'more'} not shown …\n${text.slice(text.length - tail)}`;
}

export function pick(object, keys) {
    const picked = {};

    for (const key of keys) {
        if (object?.[key] !== undefined) {
            picked[key] = object[key];
        }
    }

    return picked;
}

/**
 * The values of settings that look secret (keys, tokens, passwords) and look like one (not a plain word like
 * "subscription"), plus the `extra` values (the organization's secrets), long enough to be worth hiding. Short ones
 * ("1", "true") would hide ordinary words.
 */
export function secretValues(env, extra = []) {
    const values = [
        ...Object.entries(env)
            .filter(
                ([name, value]) =>
                    /KEY|TOKEN|SECRET|PASSW|CREDENTIAL|AUTH|PRIVATE/i.test(
                        name,
                    ) &&
                    typeof value === 'string' &&
                    value.length >= 12 &&
                    !/^[a-z_-]+$/i.test(value),
            )
            .map(([, value]) => value),
        ...extra.filter(
            (value) => typeof value === 'string' && value.length >= 6,
        ),
    ];

    return [...new Set(values)].sort((a, b) => b.length - a.length);
}

/** Every string in `value` (an event) with the secrets replaced by "[hidden]". */
export function redact(value, secrets) {
    if (secrets.length === 0) {
        return value;
    }

    if (typeof value === 'string') {
        let text = value;

        for (const secret of secrets) {
            if (text.includes(secret)) {
                text = text.split(secret).join('[hidden]');
            }
        }

        return text;
    }

    if (Array.isArray(value)) {
        return value.map((item) => redact(item, secrets));
    }

    if (value && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value).map(([key, item]) => [
                key,
                redact(item, secrets),
            ]),
        );
    }

    return value;
}

/**
 * Hides secrets in streamed text, where one may arrive split across pieces: the end of each block is held back until
 * it can't be the start of a secret. Secrets longer than the holdback could show their start until the finished
 * reply (redacted in full) replaces the stream.
 */
export class StreamRedactor {
    constructor(secrets) {
        this.secrets = secrets;
        this.holdback = Math.min(
            MAX_HOLDBACK,
            Math.max(0, ...secrets.map((secret) => secret.length - 1)),
        );
        this.blocks = new Map();
    }

    /** The delta that's safe to send now for `delta` arriving in `block` (maybe empty). */
    push(block, delta) {
        const state = this.blocks.get(block) ?? { text: '', sent: 0 };
        state.text += delta;
        this.blocks.set(block, state);

        let end = Math.max(state.sent, state.text.length - this.holdback);

        // Never cut through a secret.
        for (const secret of this.secrets) {
            let at = state.text.indexOf(secret, Math.max(0, state.sent));

            while (at !== -1) {
                if (at < end && at + secret.length > end) {
                    end = at;
                }

                at = state.text.indexOf(secret, at + 1);
            }
        }

        const chunk = state.text.slice(state.sent, end);
        state.sent = end;

        return redact(chunk, this.secrets);
    }
}

/** A unified diff of a new file's contents. */
export function newFileDiff(content) {
    if (typeof content !== 'string') {
        return undefined;
    }

    const lines = content.replace(/\n$/, '').split('\n');

    return `@@ -0,0 +1,${lines.length} @@\n${lines.map((line) => `+${line}`).join('\n')}`;
}

/** A unified diff from Claude Code's structured patch ({oldStart, oldLines, newStart, newLines, lines} hunks). */
export function structuredPatchDiff(hunks) {
    if (!Array.isArray(hunks) || hunks.length === 0) {
        return undefined;
    }

    return hunks
        .map(
            (hunk) =>
                `@@ -${hunk.oldStart},${hunk.oldLines} +${hunk.newStart},${hunk.newLines} @@\n${(hunk.lines ?? []).join('\n')}`,
        )
        .join('\n');
}

/** Merges consecutive pieces of the same block, so a batch carries one per block. */
export function coalesce(events) {
    const merged = [];

    for (const event of events) {
        const last = merged.at(-1);

        if (
            event.type === 'onedrop.delta' &&
            last?.type === 'onedrop.delta' &&
            last.block === event.block
        ) {
            merged[merged.length - 1] = {
                ...last,
                delta: last.delta + event.delta,
            };
        } else {
            merged.push(event);
        }
    }

    return merged.filter(
        (event) => event.type !== 'onedrop.delta' || event.delta !== '',
    );
}

// The fields of a tool call's input the platform shows (the rest, like a whole file's contents, stays out).
const INPUT_FIELDS = [
    'file_path',
    'notebook_path',
    'path',
    'command',
    'description',
    'pattern',
    'url',
    'query',
];

/**
 * Claude Code's `--output-format stream-json --verbose --include-partial-messages` events, compacted: the stream's
 * pieces become `onedrop.delta`s, each finished text or thinking block carries the id its pieces had (`block`), and
 * tool results come with a little of their output and, for edits, a diff.
 */
export class ClaudeTranslator {
    constructor(run) {
        this.run = run;
        this.count = 0;
        // The current message's text and thinking blocks being streamed, by index.
        this.streaming = new Map();
        // Its streamed blocks in order, until the assistant event that repeats them (which comes just before each
        // block's stop).
        this.unclaimed = [];
        // Tool calls by id: their name and new file contents, for their results.
        this.tools = new Map();
    }

    translate(event) {
        switch (event.type) {
            case 'stream_event':
                return event.parent_tool_use_id
                    ? []
                    : this.streamed(event.event ?? {});
            case 'assistant':
                return [this.assistant(event)];
            case 'user':
                return this.toolResults(event);
            case 'result':
                return [
                    {
                        ...pick(event, [
                            'type',
                            'subtype',
                            'is_error',
                            'result',
                            'errors',
                            'api_error_status',
                            'session_id',
                            // 0 for a command that only shows something, like /context (AGT-016).
                            'num_turns',
                        ]),
                        // Tokens and estimated cost per model, for the Usage page.
                        modelUsage: Object.fromEntries(
                            Object.entries(event.modelUsage ?? {}).map(
                                ([model, usage]) => [
                                    model,
                                    pick(usage, [
                                        'inputTokens',
                                        'outputTokens',
                                        'cacheReadInputTokens',
                                        'cacheCreationInputTokens',
                                        'costUSD',
                                    ]),
                                ],
                            ),
                        ),
                    },
                ];
            case 'system':
                // Its slash commands, for the chat's menu (AGT-016), with short descriptions.
                if (event.subtype === 'commands_changed') {
                    return [
                        {
                            type: event.type,
                            subtype: event.subtype,
                            commands: (event.commands ?? []).map((command) => ({
                                ...pick(command, [
                                    'name',
                                    'argumentHint',
                                    'builtin',
                                ]),
                                description: String(
                                    command.description ?? '',
                                ).slice(0, 300),
                            })),
                        },
                    ];
                }

                return ['init', 'api_retry'].includes(event.subtype)
                    ? [pick(event, ['type', 'subtype', 'session_id', 'error'])]
                    : [];
            default:
                return [];
        }
    }

    streamed(event) {
        switch (event.type) {
            case 'message_start':
                this.streaming = new Map();
                this.unclaimed = [];

                return [];
            case 'content_block_start': {
                const type = event.content_block?.type;

                if (type === 'text' || type === 'thinking') {
                    const block = { type, id: `${this.run}-${++this.count}` };

                    this.streaming.set(event.index, block);
                    this.unclaimed.push(block);
                }

                return [];
            }
            case 'content_block_delta': {
                const block = this.streaming.get(event.index);
                const delta =
                    event.delta?.type === 'text_delta'
                        ? event.delta.text
                        : event.delta?.type === 'thinking_delta'
                          ? event.delta.thinking
                          : undefined;

                return block && typeof delta === 'string'
                    ? [
                          {
                              type: 'onedrop.delta',
                              block: block.id,
                              kind: block.type === 'text' ? 'text' : 'thinking',
                              delta,
                          },
                      ]
                    : [];
            }
            case 'content_block_stop':
                this.streaming.delete(event.index);

                return [];
            default:
                return [];
        }
    }

    // The id the next block of this type was streamed with, if any.
    claim(type) {
        const index = this.unclaimed.findIndex((block) => block.type === type);

        return index === -1 ? undefined : this.unclaimed.splice(index, 1)[0].id;
    }

    assistant(event) {
        const topLevel = !event.parent_tool_use_id;

        return {
            type: 'assistant',
            session_id: event.session_id,
            message: {
                model: event.message?.model,
                content: (event.message?.content ?? []).map((block) => {
                    if (block.type === 'text') {
                        return {
                            type: 'text',
                            text: block.text,
                            block: topLevel ? this.claim('text') : undefined,
                        };
                    }

                    if (block.type === 'thinking') {
                        return {
                            type: 'thinking',
                            thinking: clip(block.thinking, LIMITS.thinking),
                            block: topLevel
                                ? this.claim('thinking')
                                : undefined,
                        };
                    }

                    if (block.type === 'tool_use') {
                        const input = pick(block.input, INPUT_FIELDS);

                        if (typeof input.command === 'string') {
                            input.command = input.command.slice(
                                0,
                                LIMITS.command,
                            );
                        }

                        this.tools.set(block.id, {
                            name: block.name,
                            content: block.input?.content,
                        });

                        return {
                            type: 'tool_use',
                            id: block.id,
                            name: block.name,
                            input,
                        };
                    }

                    return { type: block.type };
                }),
            },
        };
    }

    toolResults(event) {
        const results = (event.message?.content ?? []).filter(
            (block) => block?.type === 'tool_result',
        );

        if (results.length === 0) {
            return [];
        }

        return [
            {
                type: 'user',
                session_id: event.session_id,
                message: {
                    content: results.map((block) => {
                        const tool = this.tools.get(block.tool_use_id) ?? {};
                        const output = Array.isArray(block.content)
                            ? block.content
                                  .filter((part) => part?.type === 'text')
                                  .map((part) => part.text)
                                  .join('\n')
                            : block.content;
                        // Only one result's details come with the event.
                        const result =
                            results.length === 1
                                ? event.tool_use_result
                                : undefined;
                        const diff =
                            structuredPatchDiff(result?.structuredPatch) ??
                            (tool.name === 'Write' && !block.is_error
                                ? newFileDiff(result?.content ?? tool.content)
                                : undefined);

                        return {
                            type: 'tool_result',
                            tool_use_id: block.tool_use_id,
                            is_error: Boolean(block.is_error),
                            // A file's contents add nothing the Files panel doesn't show.
                            content:
                                tool.name === 'Read' && !block.is_error
                                    ? undefined
                                    : clip(output, LIMITS.output),
                            diff: clip(diff, LIMITS.diff),
                        };
                    }),
                },
            },
        ];
    }
}

/**
 * OpenCode's server events (`GET /event` of `opencode serve`), for the run's own session, as `opencode run --format
 * json` prints them: text and thinking once finished, tool calls when they start and finish, and step results. Its
 * pieces of text and thinking become `onedrop.delta`s. `idle` says the session finished its turn.
 */
export class OpenCodeTranslator {
    constructor(model, sessionId = null) {
        this.model = model;
        this.session = sessionId || null;
        this.roles = new Map();
        this.parts = new Map();
        this.sent = new Set();
        this.toolStates = new Map();
        this.idle = false;
        this.busy = false;
    }

    ours(sessionId) {
        return this.session !== null && sessionId === this.session;
    }

    translate(event) {
        const properties = event.properties ?? {};

        switch (event.type) {
            case 'session.created':
                // A subtask's session has a parent; the run's is the first without one.
                if (this.session === null && !properties.info?.parentID) {
                    this.session = properties.info?.id ?? null;
                }

                return [];
            case 'session.status':
                if (this.ours(properties.sessionID)) {
                    if (properties.status?.type === 'busy') {
                        this.busy = true;
                    } else if (
                        properties.status?.type === 'idle' &&
                        this.busy
                    ) {
                        this.idle = true;
                    }
                }

                return [];
            case 'session.idle':
                if (this.ours(properties.sessionID) && this.busy) {
                    this.idle = true;
                }

                return [];
            case 'session.error':
                return !properties.sessionID || this.ours(properties.sessionID)
                    ? [
                          {
                              type: 'error',
                              sessionID: properties.sessionID,
                              error: properties.error,
                          },
                      ]
                    : [];
            case 'message.updated':
                if (properties.info?.id) {
                    this.roles.set(properties.info.id, properties.info.role);
                }

                return [];
            case 'message.part.updated':
                return this.part(properties.part ?? {});
            case 'message.part.delta': {
                const type = this.parts.get(properties.partID);

                return this.ours(properties.sessionID) &&
                    this.roles.get(properties.messageID) === 'assistant' &&
                    properties.field === 'text' &&
                    (type === 'text' || type === 'reasoning') &&
                    typeof properties.delta === 'string'
                    ? [
                          {
                              type: 'onedrop.delta',
                              block: properties.partID,
                              kind: type === 'text' ? 'text' : 'thinking',
                              delta: properties.delta,
                          },
                      ]
                    : [];
            }
            default:
                return [];
        }
    }

    part(part) {
        if (!this.ours(part.sessionID)) {
            return [];
        }

        this.parts.set(part.id, part.type);

        if (this.roles.get(part.messageID) !== 'assistant') {
            return [];
        }

        const base = { sessionID: part.sessionID };

        switch (part.type) {
            case 'text':
            case 'reasoning':
                if (
                    !part.time?.end ||
                    part.synthetic ||
                    this.sent.has(part.id)
                ) {
                    return [];
                }

                this.sent.add(part.id);

                return [
                    {
                        ...base,
                        type: part.type,
                        part: {
                            id: part.id,
                            type: part.type,
                            text:
                                part.type === 'reasoning'
                                    ? clip(part.text, LIMITS.thinking)
                                    : part.text,
                        },
                    },
                ];
            case 'tool': {
                const status = part.state?.status;

                // Pending has no input yet; running and finished are each reported once.
                if (
                    status === 'pending' ||
                    this.toolStates.get(part.id) === status
                ) {
                    return [];
                }

                this.toolStates.set(part.id, status);

                return [{ ...base, type: 'tool_use', part: this.tool(part) }];
            }
            case 'step-finish':
                return [
                    {
                        ...base,
                        type: 'step_finish',
                        model: this.model,
                        part: pick(part, [
                            'id',
                            'type',
                            'reason',
                            'sessionID',
                            'tokens',
                            'cost',
                        ]),
                    },
                ];
            default:
                return [];
        }
    }

    tool(part) {
        const state = part.state ?? {};
        const input = pick(state.input, [
            'filePath',
            'path',
            'command',
            'description',
            'pattern',
            'url',
            'query',
        ]);

        if (typeof input.command === 'string') {
            input.command = input.command.slice(0, LIMITS.command);
        }

        const diff =
            state.metadata?.diff ??
            (part.tool === 'write' && state.status === 'completed'
                ? newFileDiff(state.input?.content)
                : undefined);

        return {
            id: part.id,
            callID: part.callID,
            type: 'tool',
            tool: part.tool,
            state: {
                status: state.status,
                input,
                // A file's contents add nothing the Files panel doesn't show.
                output:
                    part.tool === 'read'
                        ? undefined
                        : clip(state.output, LIMITS.output),
                error: clip(state.error, LIMITS.output),
                metadata: { diff: clip(diff, LIMITS.diff) },
            },
        };
    }
}

/**
 * Codex's app-server notifications (`codex app-server`), as `codex exec --json` prints its events: items starting
 * and finishing (with their output, diffs and thinking), the turn's tokens when it ends, and retries. Items' ids get
 * the run's in front, since Codex numbers some of them afresh each run. `result` is set once the turn is over.
 */
export class CodexTranslator {
    constructor(run, model) {
        this.run = run;
        this.model = model;
        this.usage = {};
        this.summaries = new Map();
        this.result = null;
    }

    id(itemId) {
        return `${this.run}-${itemId}`;
    }

    translate(message) {
        const params = message.params ?? {};

        switch (message.method) {
            case 'thread/started':
                return params.thread?.id
                    ? [{ type: 'thread.started', thread_id: params.thread.id }]
                    : [];
            case 'item/started':
            case 'item/completed': {
                const item = this.item(params.item ?? {});

                return item
                    ? [
                          {
                              type:
                                  message.method === 'item/started'
                                      ? 'item.started'
                                      : 'item.completed',
                              item,
                          },
                      ]
                    : [];
            }
            case 'item/agentMessage/delta':
                return this.delta(params.itemId, 'text', params.delta);
            case 'item/reasoning/summaryTextDelta': {
                // Each part of the summary is a paragraph.
                const last = this.summaries.get(params.itemId);
                this.summaries.set(params.itemId, params.summaryIndex ?? 0);

                return this.delta(
                    params.itemId,
                    'thinking',
                    (last !== undefined && last !== params.summaryIndex
                        ? '\n\n'
                        : '') + (params.delta ?? ''),
                );
            }
            case 'item/reasoning/textDelta':
                // The raw thinking, for models without a summary of it.
                return this.summaries.has(params.itemId)
                    ? []
                    : this.delta(params.itemId, 'thinking', params.delta);
            case 'thread/tokenUsage/updated': {
                // One model request's tokens; the turn's are their sum.
                const last = params.tokenUsage?.last ?? {};

                for (const [key, value] of Object.entries(last)) {
                    this.usage[key] = (this.usage[key] ?? 0) + (value ?? 0);
                }

                return [];
            }
            case 'turn/completed': {
                const turn = params.turn ?? {};
                this.result = turn.status ?? 'completed';
                const events = [
                    {
                        type: 'turn.completed',
                        model: this.model,
                        usage: {
                            input_tokens: this.usage.inputTokens ?? 0,
                            cached_input_tokens:
                                this.usage.cachedInputTokens ?? 0,
                            cache_write_input_tokens:
                                this.usage.cacheWriteInputTokens ?? 0,
                            output_tokens: this.usage.outputTokens ?? 0,
                            reasoning_output_tokens:
                                this.usage.reasoningOutputTokens ?? 0,
                        },
                    },
                ];

                if (turn.status === 'failed') {
                    events.push({
                        type: 'turn.failed',
                        error: {
                            message: turn.error?.message ?? 'unknown error',
                        },
                    });
                }

                return events;
            }
            case 'error':
                // Retried by Codex; a failure that sticks fails the turn.
                return params.willRetry
                    ? [
                          {
                              type: 'error',
                              message:
                                  `Reconnecting… ${params.error?.message ?? ''}`.trim(),
                          },
                      ]
                    : [];
            default:
                return [];
        }
    }

    delta(itemId, kind, delta) {
        return typeof delta === 'string' && delta !== ''
            ? [{ type: 'onedrop.delta', block: this.id(itemId), kind, delta }]
            : [];
    }

    item(item) {
        const id = this.id(item.id);

        switch (item.type) {
            case 'agentMessage':
                return { id, type: 'agent_message', text: item.text ?? '' };
            case 'reasoning': {
                const summary = (item.summary ?? []).join('\n\n');

                return {
                    id,
                    type: 'reasoning',
                    text: clip(
                        summary || (item.content ?? []).join('\n\n'),
                        LIMITS.thinking,
                    ),
                };
            }
            case 'commandExecution':
                return {
                    id,
                    type: 'command_execution',
                    command: (item.command ?? '').slice(0, LIMITS.command),
                    aggregated_output: clip(
                        item.aggregatedOutput ?? undefined,
                        LIMITS.output,
                    ),
                    exit_code: item.exitCode ?? null,
                    status:
                        item.status === 'inProgress'
                            ? 'in_progress'
                            : item.status === 'completed'
                              ? 'completed'
                              : 'failed',
                };
            case 'fileChange':
                return {
                    id,
                    type: 'file_change',
                    status: item.status === 'failed' ? 'failed' : 'completed',
                    changes: (item.changes ?? []).map((change) => ({
                        path: change.path,
                        kind:
                            change.kind?.type === 'add'
                                ? 'add'
                                : change.kind?.type === 'delete'
                                  ? 'delete'
                                  : 'update',
                        diff: clip(change.diff, LIMITS.diff),
                    })),
                };
            case 'mcpToolCall':
                return {
                    id,
                    type: 'mcp_tool_call',
                    server: item.server,
                    tool: item.tool,
                    status: item.status === 'failed' ? 'failed' : item.status,
                    error: clip(item.error?.message, LIMITS.output),
                };
            case 'webSearch':
                return { id, type: 'web_search', query: item.query };
            case 'plan':
                return { id, type: 'todo_list' };
            default:
                return null;
        }
    }
}
