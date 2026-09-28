<?php

namespace App\Concerns;

use App\Enums\AgentProvider;
use App\Models\User;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ValidatesAgentSelection
{
    /**
     * Validate an optional agent_provider/agent_model/agent_variant choice against the user's
     * usable providers and the catalog.
     *
     * @return array{agent_provider: AgentProvider, agent_model: string, agent_variant: string|null}|null
     *
     * @throws ValidationException
     */
    protected function validatedAgentSelection(Request $request, User $user, ModelCatalog $catalog, bool $required = false): ?array
    {
        $validated = $request->validate([
            'agent_provider' => [$required ? 'required' : 'nullable', Rule::enum(AgentProvider::class)],
            'agent_model' => ['required_with:agent_provider', 'nullable', 'string', 'max:200'],
            'agent_variant' => ['nullable', 'string', 'max:40'],
        ]);

        if (empty($validated['agent_provider'])) {
            return null;
        }

        $provider = AgentProvider::from($validated['agent_provider']);

        if (! in_array($provider, $catalog->usableProviders($user), true)) {
            throw ValidationException::withMessages(['agent_provider' => __('Connect :provider in Settings → AI first.', ['provider' => $provider->pickerLabel()])]);
        }

        $model = $catalog->find($provider, $validated['agent_model'], $user);

        if (! $model) {
            throw ValidationException::withMessages(['agent_model' => __("That model isn't available from :provider.", ['provider' => $provider->pickerLabel()])]);
        }

        $variant = $validated['agent_variant'] ?? null;

        if ($variant !== null && ! in_array($variant, $model['efforts'], true)) {
            throw ValidationException::withMessages(['agent_variant' => __("That reasoning level isn't available for :model.", ['model' => $model['name']])]);
        }

        return ['agent_provider' => $provider, 'agent_model' => $model['id'], 'agent_variant' => $variant];
    }
}
