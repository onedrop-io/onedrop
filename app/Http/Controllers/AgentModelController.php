<?php

namespace App\Http\Controllers;

use App\Enums\AgentProvider;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentModelController extends Controller
{
    /**
     * Models the user can pick, grouped by their connected providers, plus their favorites and recently chosen ones.
     */
    public function index(Request $request, ModelCatalog $catalog): JsonResponse
    {
        return response()->json([
            'providers' => array_map(fn (AgentProvider $provider) => [
                'id' => $provider->value,
                'label' => $provider->pickerLabel(),
                'default_model' => $catalog->defaultModel($provider, $request->user()),
                'models' => $catalog->models($provider, $request->user()),
            ], $catalog->usableProviders($request->user())),
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
