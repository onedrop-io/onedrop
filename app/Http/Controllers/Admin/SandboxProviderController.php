<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BuildSandboxTemplate;
use App\Models\Sandbox;
use App\Models\SystemSetting;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxMover;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxProviders;
use App\Sandbox\SandboxTemplates;
use App\Sandbox\SystemConfig;
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
            // The images the app builds itself, as each provider's last build is doing (SBX-014).
            'templateBuilds' => collect(SandboxTemplates::PROVIDERS)->mapWithKeys(fn (string $name) => [$name => app(SandboxTemplates::class)->status($name)])->filter()->all(),
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
        $saved = SystemSetting::group(SandboxProviders::SETTING);
        $providers->update($provider, $validated['enabled'], $validated);

        // A new size, or a provider just set up, needs its image built first (SBX-014); new projects can't go there
        // until it's ready.
        if (app(SandboxTemplates::class)->needsBuild($provider)) {
            BuildSandboxTemplate::dispatch($provider);
        }

        $this->undoUnlessItCanMakeSandboxes($providers, $active, $saved, 'enabled');

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
        $saved = SystemSetting::group(SandboxProviders::SETTING);
        $providers->reorder($validated['providers']);
        $this->undoUnlessItCanMakeSandboxes($providers, $active, $saved, 'providers');

        $this->toast($providers, $active, __('Order saved.'), $mover->moveMisplaced());

        return to_route('admin.sandboxes.index');
    }

    /**
     * New projects, and every move (SBX-005), go to the first provider that's on: put the settings back as they were
     * if it can't make sandboxes yet (its image isn't built there), rather than have them all fail.
     *
     * @param  array<string, mixed>  $saved  the settings before the change
     *
     * @throws ValidationException
     */
    protected function undoUnlessItCanMakeSandboxes(SandboxProviders $providers, string $before, array $saved, string $field): void
    {
        $after = $providers->active();

        if ($after === $before || ! isset(SandboxProviders::PROVIDERS[$after])) {
            return;
        }

        try {
            app(SandboxProvider::class)->checkImage();
        } catch (SandboxException $e) {
            SystemSetting::put(SandboxProviders::SETTING, $saved);
            SystemConfig::saved();

            throw ValidationException::withMessages([$field => __('Projects can\'t move to :provider yet: :reason', [
                'provider' => SandboxProviders::PROVIDERS[$after]['label'],
                'reason' => $e->getMessage(),
            ])]);
        }
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
