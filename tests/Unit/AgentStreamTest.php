<?php

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Run agent-stream.mjs in Node: `$code` is the body of a function of `m` (the module) and `input`, whose return
 * value comes back decoded.
 */
function agentStream(string $code, mixed $input = null): mixed
{
    $module = base_path('docker/sandbox/agent-stream.mjs');
    $result = Process::input(json_encode($input))->run([
        'node', '--input-type=module', '-e',
        "import * as m from '{$module}';"
        ."let raw = ''; process.stdin.on('data', (chunk) => raw += chunk);"
        ."process.stdin.on('end', () => { const input = JSON.parse(raw); const run = (m, input) => { {$code} }; console.log(JSON.stringify(run(m, input) ?? null)); });",
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true);
}

test('Claude Code\'s partial messages become pieces of a block, and the finished block carries its id', function () {
    $events = agentStream('const t = new m.ClaudeTranslator("run"); return input.flatMap((e) => t.translate(e));', [
        ['type' => 'stream_event', 'event' => ['type' => 'message_start']],
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking']]],
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Hmm']]],
        // The assistant event repeats each block just before the block's stop.
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'thinking', 'thinking' => 'Hmm']]]],
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_stop', 'index' => 0]],
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text']]],
        ['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]],
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Hi']]]],
        // A subtask's stream isn't the chat's.
        ['type' => 'stream_event', 'parent_tool_use_id' => 'toolu_9', 'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'x']]],
    ]);

    expect($events)->toBe([
        ['type' => 'onedrop.delta', 'block' => 'run-1', 'kind' => 'thinking', 'delta' => 'Hmm'],
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'thinking', 'thinking' => 'Hmm', 'block' => 'run-1']]]],
        ['type' => 'onedrop.delta', 'block' => 'run-2', 'kind' => 'text', 'delta' => 'Hi'],
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Hi', 'block' => 'run-2']]]],
    ]);
})->group('AGT-017');

test('Claude Code\'s tool results keep a little output and turn edits and new files into diffs', function () {
    $events = agentStream('const t = new m.ClaudeTranslator("run"); return input.flatMap((e) => t.translate(e)).filter((e) => e.type === "user").map((e) => e.message.content[0]);', [
        ['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => 'a', 'name' => 'Read', 'input' => ['file_path' => '/workspace/x']],
            ['type' => 'tool_use', 'id' => 'b', 'name' => 'Edit', 'input' => ['file_path' => '/workspace/x', 'old_string' => 'a', 'new_string' => 'b']],
            ['type' => 'tool_use', 'id' => 'c', 'name' => 'Write', 'input' => ['file_path' => '/workspace/y', 'content' => "one\ntwo\n"]],
        ]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'a', 'content' => 'the whole file']]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'b', 'content' => [['type' => 'text', 'text' => 'Updated']]]]],
            'tool_use_result' => ['structuredPatch' => [['oldStart' => 3, 'oldLines' => 1, 'newStart' => 3, 'newLines' => 1, 'lines' => ['-a', '+b']]]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'c', 'content' => 'Created']]], 'tool_use_result' => ['type' => 'create']],
    ]);

    expect($events)->toBe([
        ['type' => 'tool_result', 'tool_use_id' => 'a', 'is_error' => false],
        ['type' => 'tool_result', 'tool_use_id' => 'b', 'is_error' => false, 'content' => 'Updated', 'diff' => "@@ -3,1 +3,1 @@\n-a\n+b"],
        ['type' => 'tool_result', 'tool_use_id' => 'c', 'is_error' => false, 'content' => 'Created', 'diff' => "@@ -0,0 +1,2 @@\n+one\n+two"],
    ]);
})->group('AGT-017');

test('OpenCode\'s server events become its run events for the run\'s own session', function () {
    $part = fn (array $part) => ['type' => 'message.part.updated', 'properties' => ['part' => ['sessionID' => 'ses_1', 'messageID' => 'msg_a', ...$part]]];

    $events = agentStream('const t = new m.OpenCodeTranslator("anthropic/claude", null); const out = input.flatMap((e) => t.translate(e)); return { out, idle: t.idle };', [
        ['type' => 'session.created', 'properties' => ['info' => ['id' => 'ses_1']]],
        ['type' => 'session.created', 'properties' => ['info' => ['id' => 'ses_sub', 'parentID' => 'ses_1']]],
        ['type' => 'message.updated', 'properties' => ['info' => ['id' => 'msg_u', 'role' => 'user', 'sessionID' => 'ses_1']]],
        ['type' => 'message.part.updated', 'properties' => ['part' => ['id' => 'prt_u', 'sessionID' => 'ses_1', 'messageID' => 'msg_u', 'type' => 'text', 'text' => 'Build it', 'time' => ['end' => 1]]]],
        ['type' => 'session.status', 'properties' => ['sessionID' => 'ses_1', 'status' => ['type' => 'busy']]],
        ['type' => 'message.updated', 'properties' => ['info' => ['id' => 'msg_a', 'role' => 'assistant', 'sessionID' => 'ses_1']]],
        $part(['id' => 'prt_t', 'type' => 'text', 'text' => '']),
        ['type' => 'message.part.delta', 'properties' => ['sessionID' => 'ses_1', 'messageID' => 'msg_a', 'partID' => 'prt_t', 'field' => 'text', 'delta' => 'Do']],
        $part(['id' => 'prt_t', 'type' => 'text', 'text' => 'Done', 'time' => ['end' => 2]]),
        $part(['id' => 'prt_t', 'type' => 'text', 'text' => 'Done', 'time' => ['end' => 2]]),
        $part(['id' => 'prt_x', 'type' => 'tool', 'callID' => 'c1', 'tool' => 'read', 'state' => ['status' => 'pending', 'input' => []]]),
        $part(['id' => 'prt_x', 'type' => 'tool', 'callID' => 'c1', 'tool' => 'read', 'state' => ['status' => 'running', 'input' => ['filePath' => '/workspace/a', 'limit' => 5]]]),
        $part(['id' => 'prt_x', 'type' => 'tool', 'callID' => 'c1', 'tool' => 'read', 'state' => ['status' => 'running', 'input' => ['filePath' => '/workspace/a']]]),
        $part(['id' => 'prt_x', 'type' => 'tool', 'callID' => 'c1', 'tool' => 'read', 'state' => ['status' => 'completed', 'input' => ['filePath' => '/workspace/a'], 'output' => 'file contents']]),
        ['type' => 'message.part.updated', 'properties' => ['part' => ['id' => 'prt_s', 'sessionID' => 'ses_sub', 'messageID' => 'msg_s', 'type' => 'text', 'text' => 'sub', 'time' => ['end' => 3]]]],
        ['type' => 'session.status', 'properties' => ['sessionID' => 'ses_1', 'status' => ['type' => 'idle']]],
    ]);

    expect($events['out'])->toBe([
        ['type' => 'onedrop.delta', 'block' => 'prt_t', 'kind' => 'text', 'delta' => 'Do'],
        ['sessionID' => 'ses_1', 'type' => 'text', 'part' => ['id' => 'prt_t', 'type' => 'text', 'text' => 'Done']],
        ['sessionID' => 'ses_1', 'type' => 'tool_use', 'part' => ['id' => 'prt_x', 'callID' => 'c1', 'type' => 'tool', 'tool' => 'read', 'state' => ['status' => 'running', 'input' => ['filePath' => '/workspace/a'], 'metadata' => []]]],
        ['sessionID' => 'ses_1', 'type' => 'tool_use', 'part' => ['id' => 'prt_x', 'callID' => 'c1', 'type' => 'tool', 'tool' => 'read', 'state' => ['status' => 'completed', 'input' => ['filePath' => '/workspace/a'], 'metadata' => []]]],
    ])->and($events['idle'])->toBeTrue();
})->group('AGT-017');

test('Codex\'s app-server notifications become its exec events, with the turn\'s tokens summed', function () {
    $events = agentStream('const t = new m.CodexTranslator("run", "gpt-5"); const out = input.flatMap((e) => t.translate(e)); return { out, result: t.result };', [
        ['method' => 'item/started', 'params' => ['item' => ['type' => 'commandExecution', 'id' => 'c1', 'command' => 'ls', 'status' => 'inProgress', 'aggregatedOutput' => null, 'exitCode' => null]]],
        ['method' => 'item/completed', 'params' => ['item' => ['type' => 'commandExecution', 'id' => 'c1', 'command' => 'ls', 'status' => 'completed', 'aggregatedOutput' => "a\n", 'exitCode' => 0]]],
        ['method' => 'item/reasoning/summaryTextDelta', 'params' => ['itemId' => 'r1', 'delta' => 'Plan', 'summaryIndex' => 0]],
        ['method' => 'item/reasoning/summaryTextDelta', 'params' => ['itemId' => 'r1', 'delta' => 'Next', 'summaryIndex' => 1]],
        ['method' => 'item/reasoning/textDelta', 'params' => ['itemId' => 'r1', 'delta' => 'raw']],
        ['method' => 'item/completed', 'params' => ['item' => ['type' => 'reasoning', 'id' => 'r1', 'summary' => ['Plan', 'Next'], 'content' => ['raw']]]],
        ['method' => 'item/agentMessage/delta', 'params' => ['itemId' => 'm1', 'delta' => 'Hi']],
        ['method' => 'item/completed', 'params' => ['item' => ['type' => 'fileChange', 'id' => 'f1', 'status' => 'completed', 'changes' => [['path' => '/workspace/a', 'kind' => ['type' => 'add'], 'diff' => '+x']]]]],
        ['method' => 'error', 'params' => ['willRetry' => true, 'error' => ['message' => 'stream disconnected']]],
        ['method' => 'thread/tokenUsage/updated', 'params' => ['tokenUsage' => ['last' => ['inputTokens' => 10, 'cachedInputTokens' => 4, 'outputTokens' => 2]]]],
        ['method' => 'thread/tokenUsage/updated', 'params' => ['tokenUsage' => ['last' => ['inputTokens' => 5, 'cachedInputTokens' => 0, 'outputTokens' => 1]]]],
        ['method' => 'turn/completed', 'params' => ['turn' => ['status' => 'failed', 'error' => ['message' => 'Quota exceeded']]]],
    ]);

    expect($events['out'])->toBe([
        ['type' => 'item.started', 'item' => ['id' => 'run-c1', 'type' => 'command_execution', 'command' => 'ls', 'exit_code' => null, 'status' => 'in_progress']],
        ['type' => 'item.completed', 'item' => ['id' => 'run-c1', 'type' => 'command_execution', 'command' => 'ls', 'aggregated_output' => "a\n", 'exit_code' => 0, 'status' => 'completed']],
        ['type' => 'onedrop.delta', 'block' => 'run-r1', 'kind' => 'thinking', 'delta' => 'Plan'],
        ['type' => 'onedrop.delta', 'block' => 'run-r1', 'kind' => 'thinking', 'delta' => "\n\nNext"],
        ['type' => 'item.completed', 'item' => ['id' => 'run-r1', 'type' => 'reasoning', 'text' => "Plan\n\nNext"]],
        ['type' => 'onedrop.delta', 'block' => 'run-m1', 'kind' => 'text', 'delta' => 'Hi'],
        ['type' => 'item.completed', 'item' => ['id' => 'run-f1', 'type' => 'file_change', 'status' => 'completed', 'changes' => [['path' => '/workspace/a', 'kind' => 'add', 'diff' => '+x']]]],
        ['type' => 'error', 'message' => 'Reconnecting… stream disconnected'],
        ['type' => 'turn.completed', 'model' => 'gpt-5', 'usage' => ['input_tokens' => 15, 'cached_input_tokens' => 4, 'cache_write_input_tokens' => 0, 'output_tokens' => 3, 'reasoning_output_tokens' => 0]],
        ['type' => 'turn.failed', 'error' => ['message' => 'Quota exceeded']],
    ])->and($events['result'])->toBe('failed');
})->group('AGT-017');

test('secrets are hidden in events, and never sent even when a stream splits one across pieces', function () {
    $result = agentStream(<<<'JS'
        const secrets = m.secretValues(
            { ANTHROPIC_API_KEY: 'sk-ant-0123456789', APP_CLAUDE_AUTH: 'subscription', SHORT_TOKEN: 'abc', HOME: '/home/sandbox/secret-path-1' },
            ['org-secret-1'],
        );
        const redactor = new m.StreamRedactor(secrets);
        const sent = ['Your key is sk-an', 't-0123456789 and', ' org-sec', 'ret-1 done.'].map((piece) => redactor.push('b', piece));
        return {
            secrets,
            event: m.redact({ output: 'key=sk-ant-0123456789', nested: ['org-secret-1'] }, secrets),
            sent,
        };
        JS);

    expect($result['secrets'])->toBe(['sk-ant-0123456789', 'org-secret-1'])
        ->and($result['event'])->toBe(['output' => 'key=[hidden]', 'nested' => ['[hidden]']])
        // The end stays held back: the saved reply replaces the stream.
        ->and(implode('', $result['sent']))->toBe('Your key is [hidden] and ')
        ->and($result['sent'][0])->not->toContain('sk');
})->group('AGT-017');

test('long output keeps its start and end, and a batch\'s pieces of one block go as one', function () {
    $result = agentStream(<<<'JS'
        return {
            clipped: m.clip(Array.from({ length: 100 }, (_, i) => `line ${i}`).join('\n'), 40),
            merged: m.coalesce([
                { type: 'onedrop.delta', block: 'a', kind: 'text', delta: 'He' },
                { type: 'onedrop.delta', block: 'a', kind: 'text', delta: 'llo' },
                { type: 'text' },
                { type: 'onedrop.delta', block: 'a', kind: 'text', delta: '' },
            ]),
        };
        JS);

    expect($result['clipped'])->toStartWith('line 0')
        ->toContain('lines not shown')
        ->toEndWith('line 99')
        ->and($result['merged'])->toBe([
            ['type' => 'onedrop.delta', 'block' => 'a', 'kind' => 'text', 'delta' => 'Hello'],
            ['type' => 'text'],
        ]);
})->group('AGT-017');
