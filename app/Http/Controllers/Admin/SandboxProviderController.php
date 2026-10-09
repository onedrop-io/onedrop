<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sandbox;
use App\Sandbox\SandboxMover;
use App\Sandbox\SandboxProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Turn sandbox providers on and off, configure them, and put them in order: new projects run on the first one that's
 * on and set up (ADMIN-002), and existing ones move there at once (SBX-005).
 */
class SandboxProviderController extends Controller
{
    /**
     * Show every provider.
     */
    public function index(SandboxProviders $providers, SandboxMover $mover): Response
    {
        return Inertia::render('admin/sandboxes', [
            'providers' => $providers->describe(
                Sandbox::query()->selectRaw('provider, COUNT(*) as count')->groupBy('provider')->pluck('count', 'provider')->map(fn ($count) => (int) $count)->all(),
            ),
            'maxTaskCopies' => config('sandbox.max_task_copies'),
            'moves' => $mover->overview(),
        ]);
    }

    /**
     * Cap how many task copies one project runs at once, or (left empty) don't (TASK-003).
     */
    public function taskCopies(Request $request, SandboxProviders $providers): RedirectResponse
    {
        $limit = $request->validate(['max_task_copies' => ['nullable', 'integer', 'min:1', 'max:1000']])['max_task_copies'] ?? null;

        $providers->limitTaskCopies($limit === null ? null : (int) $limit);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task copies saved.')]);

        return to_route('admin.sandboxes.index');
    }

    /**
     * Turn a provider on or off and save its settings.
     */
    public function update(Request $request, SandboxProviders $providers, SandboxMover $mover, string $provider): RedirectResponse
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

        $this->toast($providers, $active, __(':provider saved.', ['provider' => SandboxProviders::PROVIDERS[$provider]['label']]), $mover->moveMisplaced());

        return to_route('admin.sandboxes.index');
    }

    /**
     * Put the providers in order; existing projects move to the new first one now (SBX-005).
     */
    public function reorder(Request $request, SandboxProviders $providers, SandboxMover $mover): RedirectResponse
    {
        $names = array_keys(SandboxProviders::PROVIDERS);
        $validated = $request->validate([
            'providers' => ['required', 'array', 'size:'.count($names)],
            'providers.*' => ['required', 'string', 'distinct', Rule::in($names)],
        ]);

        $active = $providers->active();
        $providers->reorder($validated['providers']);

        $this->toast($providers, $active, __('Order saved.'), $mover->moveMisplaced());

        return to_route('admin.sandboxes.index');
    }

    /**
     * Say where new projects run when that changed, and how many sandboxes started moving; otherwise the given message.
     */
    protected function toast(SandboxProviders $providers, string $before, string $message, int $moving): void
    {
        $after = $providers->active();
        $label = SandboxProviders::PROVIDERS[$after]['label'] ?? $after;
        $message = $after !== $before && isset(SandboxProviders::PROVIDERS[$after])
            ? __('New projects now run on :provider.', ['provider' => $label])
            : $message;

        if ($moving > 0) {
            $message .= ' '.trans_choice('{1} Moving 1 sandbox to :provider.|[2,*] Moving :count sandboxes to :provider.', $moving, ['provider' => $label]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
