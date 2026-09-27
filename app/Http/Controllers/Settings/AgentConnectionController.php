<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ConnectAgent;
use App\Enums\AgentProvider;
use App\Http\Controllers\Controller;
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
     * Connect a provider with a pasted key or token.
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
        return $user->agentConnections()->orderBy('id')->get()
            ->map(fn (AgentConnection $connection): array => [
                'id' => $connection->id,
                'provider' => $connection->provider->value,
                'credential_type' => $connection->credential_type->value,
                'hint' => $connection->hint,
                'is_default' => $connection->is_default,
                'verified' => $connection->verified_at !== null,
            ])
            ->all();
    }
}
