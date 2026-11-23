<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Whether Claude Code is signed in to the user's Claude subscription in a project's sandbox (AI-005), and the chat's
 * sign-in panel, which drives Claude Code's own `claude auth login` there. Asks Claude Code itself (`claude auth
 * status`); its login never leaves the sandbox.
 */
class ClaudeLoginController extends Controller
{
    /**
     * The sign-in status of the sandbox the project's main chat, or a task's (?task=), works in.
     */
    public function show(Request $request, Project $project, SandboxProvider $provider): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json($this->status($this->conversation($request, $project), $provider));
    }

    /**
     * Once Claude Code is signed in, run the message that failed because it wasn't, so the chat carries on.
     * `waiting` says whether a message still waits for that, so the chat asks again (e.g. the check couldn't
     * reach the sandbox this time) instead of staying stuck.
     */
    public function resume(Request $request, Project $project, SandboxProvider $provider, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $conversation = $this->conversation($request, $project);
        $resumed = $conversation->getAttribute('sign_in_retry_message_id') !== null
            && $this->status($conversation, $provider)['signed_in'] === true
            && $queue->resumeAfterSignIn($conversation);

        return response()->json([
            'resumed' => $resumed,
            'waiting' => $conversation->getAttribute('sign_in_retry_message_id') !== null,
        ]);
    }

    /**
     * Start Claude Code's own sign-in, out of sight in the chat's sandbox, and answer the address of the Claude sign-in
     * page it shows. Only Claude's own pages are passed on: the sandbox runs the agent's code, so it isn't trusted to
     * name where the user signs in.
     */
    public function start(Request $request, Project $project, SandboxProvider $provider): JsonResponse
    {
        Gate::authorize('update', $project);

        $sandbox = $this->runningSandbox($this->conversation($request, $project));

        try {
            $result = $provider->exec($sandbox->external_id, [SandboxTools::PATH.'/claude-login', 'start']);
        } catch (SandboxException) {
            $result = null;
        }

        $url = trim($result->output ?? '');

        abort_unless($result?->exitCode === 0 && self::isClaudeSignInPage($url), 503, __('Claude Code\'s sign-in didn\'t start. Sign in in the Shell instead.'));

        return response()->json(['url' => $url]);
    }

    /**
     * Type the code from Claude's sign-in page into the sign-in `start` began. The code is passed in the command's
     * environment, never its arguments, and isn't kept: it only works once, with the secret that sign-in holds.
     */
    public function code(Request $request, Project $project, SandboxProvider $provider): JsonResponse
    {
        Gate::authorize('update', $project);

        $code = trim((string) $request->validate([
            'code' => ['required', 'string', 'max:500', 'regex:/^\s*[A-Za-z0-9_\-]+(#[A-Za-z0-9_\-]+)?\s*$/'],
        ], ['code.regex' => __('That doesn\'t look like the code from Claude\'s sign-in page.')])['code']);

        $conversation = $this->conversation($request, $project);
        $sandbox = $this->runningSandbox($conversation);

        try {
            $output = trim($provider->exec($sandbox->external_id, [SandboxTools::PATH.'/claude-login', 'code'], ['CLAUDE_LOGIN_CODE' => $code])->output);
        } catch (SandboxException) {
            $output = '';
        }

        if ($output !== 'signed-in') {
            // Under `sign_in`, not `code`: this sign-in is used up, so the panel starts a new one.
            throw ValidationException::withMessages(['sign_in' => match (true) {
                $output === 'expired' => __('This sign-in has expired. Open Claude\'s sign-in page again for a new code.'),
                str_starts_with($output, 'failed') => __('Claude didn\'t accept that code. Each code works once: open Claude\'s sign-in page again for a new one.'),
                default => __('Claude Code\'s sign-in didn\'t answer. Try again, or sign in in the Shell instead.'),
            }]);
        }

        return response()->json($this->status($conversation, $provider));
    }

    /**
     * Claude's sign-in page, as Claude Code shows it: https, on Claude's or Anthropic's own sites.
     */
    public static function isClaudeSignInPage(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user']) && ! isset($parts['port'])
            && str_contains($parts['path'] ?? '', '/oauth/authorize')
            && collect(['claude.com', 'claude.ai', 'anthropic.com'])->contains(fn (string $site) => $host === $site || str_ends_with($host, ".{$site}"));
    }

    /**
     * The running sandbox the chat works in; a sign-in needs one.
     */
    protected function runningSandbox(Conversation $conversation): Sandbox
    {
        $sandbox = $conversation->agentSandbox();

        abort_unless($sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null, 409, __('Your project\'s sandbox isn\'t running yet. Try again in a moment.'));

        return $sandbox;
    }

    /**
     * The project's main chat, or the task named by ?task=.
     */
    protected function conversation(Request $request, Project $project): Conversation
    {
        return $request->filled('task')
            ? $project->tasks()->findOrFail((int) $request->input('task'))
            : $project;
    }

    /**
     * @return array{signed_in: bool|null, email: string|null}
     */
    protected function status(Conversation $conversation, SandboxProvider $provider): array
    {
        $sandbox = $conversation->agentSandbox();

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return ['signed_in' => null, 'email' => null];
        }

        try {
            // Exits non-zero when signed out, but still prints its status.
            $status = json_decode($provider->exec($sandbox->external_id, ['claude', 'auth', 'status', '--json'])->output, true);
        } catch (SandboxException) {
            $status = null;
        }

        if (! is_array($status)) {
            return ['signed_in' => null, 'email' => null];
        }

        $signedIn = ($status['loggedIn'] ?? false) === true && ($status['authMethod'] ?? null) === 'claude.ai';

        return [
            'signed_in' => $signedIn,
            'email' => $signedIn && is_string($status['email'] ?? null) ? $status['email'] : null,
        ];
    }
}
