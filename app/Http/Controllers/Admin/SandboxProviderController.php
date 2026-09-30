<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sandbox;
use App\Sandbox\SandboxProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Turn sandbox providers on and off, configure them, and choose where new projects run (ADMIN-002).
 */
class SandboxProviderController extends Controller
{
    /**
     * Show every provider.
     */
    public function index(SandboxProviders $providers): Response
    {
        return Inertia::render('admin/sandboxes', [
            'providers' => $providers->describe(
                Sandbox::query()->selectRaw('provider, COUNT(*) as count')->groupBy('provider')->pluck('count', 'provider')->map(fn ($count) => (int) $count)->all(),
            ),
        ]);
    }

    /**
     * Turn a provider on or off and save its settings.
     */
    public function update(Request $request, SandboxProviders $providers, string $provider): RedirectResponse
    {
        abort_unless(isset(SandboxProviders::PROVIDERS[$provider]), 404);

        $fields = SandboxProviders::PROVIDERS[$provider]['fields'];
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            ...collect($fields)->mapWithKeys(fn (array $field, string $key) => [$key => match ($field['type']) {
                'number' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'select' => ['nullable', Rule::in($field['options'] ?? [])],
                'secret' => ['nullable', 'string', 'max:2000'],
                default => ['nullable', 'string', 'max:255'],
            }])->all(),
        ]);

        if (! $validated['enabled'] && $provider === $providers->active()) {
            throw ValidationException::withMessages(['enabled' => __('Make another provider active before turning this one off.')]);
        }

        $providers->update($provider, $validated['enabled'], $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider saved.', ['provider' => SandboxProviders::PROVIDERS[$provider]['label']])]);

        return to_route('admin.sandboxes.index');
    }

    /**
     * Make a provider the one new projects run on; existing ones move when they're next opened.
     */
    public function activate(SandboxProviders $providers, string $provider): RedirectResponse
    {
        abort_unless(isset(SandboxProviders::PROVIDERS[$provider]), 404);

        if (! in_array($provider, $providers->enabled(), true)) {
            throw ValidationException::withMessages(['provider' => __('Turn this provider on first.')]);
        }

        if (($missing = $providers->missing($provider)) !== []) {
            throw ValidationException::withMessages(['provider' => __(':provider needs its :fields first.', [
                'provider' => SandboxProviders::PROVIDERS[$provider]['label'],
                'fields' => implode(', ', array_map(fn (string $key) => strtolower(SandboxProviders::PROVIDERS[$provider]['fields'][$key]['label']), $missing)),
            ])]);
        }

        $providers->activate($provider);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New projects now run on :provider.', ['provider' => SandboxProviders::PROVIDERS[$provider]['label']])]);

        return to_route('admin.sandboxes.index');
    }
}
