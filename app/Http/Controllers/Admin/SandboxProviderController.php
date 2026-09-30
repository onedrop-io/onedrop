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
 * Turn sandbox providers on and off, configure them, and put them in order: new projects run on the first one that's
 * on and set up (ADMIN-002).
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

        if (! $validated['enabled'] && in_array($provider, $providers->enabled(), true) && $providers->isLastUsable($provider)) {
            throw ValidationException::withMessages(['enabled' => __('Turn on and set up another provider before turning this one off.')]);
        }

        $active = $providers->active();
        $providers->update($provider, $validated['enabled'], $validated);

        $this->toast($providers, $active, __(':provider saved.', ['provider' => SandboxProviders::PROVIDERS[$provider]['label']]));

        return to_route('admin.sandboxes.index');
    }

    /**
     * Put the providers in order; existing projects move to the new first one when they're next opened.
     */
    public function reorder(Request $request, SandboxProviders $providers): RedirectResponse
    {
        $names = array_keys(SandboxProviders::PROVIDERS);
        $validated = $request->validate([
            'providers' => ['required', 'array', 'size:'.count($names)],
            'providers.*' => ['required', 'string', 'distinct', Rule::in($names)],
        ]);

        $active = $providers->active();
        $providers->reorder($validated['providers']);

        $this->toast($providers, $active, __('Order saved.'));

        return to_route('admin.sandboxes.index');
    }

    /**
     * Say where new projects run when that changed, otherwise the given message.
     */
    protected function toast(SandboxProviders $providers, string $before, string $message): void
    {
        $after = $providers->active();

        Inertia::flash('toast', ['type' => 'success', 'message' => $after !== $before && isset(SandboxProviders::PROVIDERS[$after])
            ? __('New projects now run on :provider.', ['provider' => SandboxProviders::PROVIDERS[$after]['label']])
            : $message]);
    }
}
