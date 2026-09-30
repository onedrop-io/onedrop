<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ConnectAgent;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Http\Controllers\Controller;
use App\Jobs\SignOutOfClaude;
use App\Models\AgentConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AgentConnectionController extends Controller
{
    /**
     * Show the user's AI connections.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/ai', [
            'connections' => static::connectionsFor($request->user()),
        ]);
    }

    /**
     * Connect a provider with a pasted key.
     */
    public function store(Request $request, ConnectAgent $connect): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::enum(AgentProvider::class)],
            'credential' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        $provider = AgentProvider::from($validated['provider']);

        $connect->handle($request->user(), $provider, $validated['credential']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider connected.', ['provider' => $provider->label()])]);

        return $request->boolean('onboarding') ? to_route('dashboard') : back();
    }

    /**
     * Use the user's Claude subscription with Claude Code. They sign in to Claude inside their sandboxes (AI-005).
     */
    public function claudeLogin(Request $request, ConnectAgent $connect): RedirectResponse
    {
        $connect->claudeLogin($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Claude subscription added. Sign in to Claude from your project when you start building.')]);

        return $request->boolean('onboarding') ? to_route('dashboard') : back();
    }

    /**
     * Connect the user's own Ollama server by its URL, with a key if it needs one (AI-006).
     */
    public function ollamaServer(Request $request, ConnectAgent $connect): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'url:http,https', 'max:500'],
            'credential' => ['nullable', 'string', 'max:1000'],
        ]);

        $connect->ollamaServer($request->user(), $validated['url'], $validated['credential'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ollama server connected.')]);

        return $request->boolean('onboarding') ? to_route('dashboard') : back();
    }

    /**
     * Make a connection the default AI for new projects.
     */
    public function update(Request $request, AgentConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 404);

        DB::transaction(function () use ($request, $connection) {
            $request->user()->agentConnections()->update(['is_default' => false]);
            $connection->update(['is_default' => true]);
        });

        return back();
    }

    /**
     * Disconnect a provider.
     */
    public function destroy(Request $request, AgentConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 404);

        $connection->delete();

        if ($connection->credential_type === CredentialType::ClaudeLogin) {
            SignOutOfClaude::dispatch($connection->user_id);
        }

        if ($connection->is_default) {
            $request->user()->agentConnections()->oldest()->first()?->update(['is_default' => true]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider disconnected.', ['provider' => $connection->provider->label()])]);

        return back();
    }

    /**
     * The user's connections, without credentials.
     *
     * @return list<array{id: int, provider: string, credential_type: string, hint: string, is_default: bool, verified: bool}>
     */
    public static function connectionsFor(User $user): array
    {
        return array_values($user->agentConnections()->orderBy('id')->get()
            ->map(fn (AgentConnection $connection): array => [
                'id' => $connection->id,
                'provider' => $connection->provider->value,
                'credential_type' => $connection->credential_type->value,
                'hint' => $connection->hint,
                'is_default' => $connection->is_default,
                'verified' => $connection->verified_at !== null,
            ])
            ->all());
    }
}
