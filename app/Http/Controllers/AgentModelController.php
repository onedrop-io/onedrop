<?php

namespace App\Http\Controllers;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentModelController extends Controller
{
    /**
     * Models the user can pick, grouped by their connected providers, the agents they can run (with
     * the providers each can use), plus their favorites and recently chosen ones.
     */
    public function index(Request $request, ModelCatalog $catalog): JsonResponse
    {
        $user = $request->user();
        $harnesses = $catalog->harnesses($user);
        $providers = collect($harnesses)
            ->flatMap(fn (AgentHarness $harness) => $catalog->usableProviders($user, $harness))
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'harnesses' => array_map(fn (AgentHarness $harness) => [
                'id' => $harness->value,
                'label' => $harness->label(),
                'providers' => array_map(fn (AgentProvider $provider) => $provider->value, $catalog->usableProviders($user, $harness)),
            ], $harnesses),
            'providers' => array_map(fn (AgentProvider $provider) => [
                'id' => $provider->value,
                'label' => $provider->pickerLabel(),
                'default_model' => $catalog->defaultModel($provider, $user),
                'models' => $catalog->models($provider, $user),
            ], $providers),
            'favorites' => $request->user()->favorite_models ?? [],
            'recent' => $request->user()->recent_models ?? [],
        ]);
    }

    /**
     * Star or unstar a model ("provider:model").
     */
    public function favorite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::enum(AgentProvider::class)],
            'model' => ['required', 'string', 'max:200'],
            'favorite' => ['required', 'boolean'],
        ]);

        $key = "{$validated['provider']}:{$validated['model']}";
        $favorites = collect($request->user()->favorite_models ?? [])->reject(fn ($item) => $item === $key);

        if ($validated['favorite']) {
            $favorites->push($key);
        }

        $request->user()->forceFill(['favorite_models' => $favorites->values()->take(50)->all()])->save();

        return response()->json(['favorites' => $request->user()->favorite_models]);
    }
}
