<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HostedService;
use App\Sandbox\Hosting\HostingProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The install's hosting accounts (ADMIN-007): turn each provider on or off and set it up.
 */
class HostingProviderController extends Controller
{
    /**
     * Show every provider.
     */
    public function index(HostingProviders $providers): Response
    {
        return Inertia::render('admin/hosting', [
            'providers' => $providers->describe(
                HostedService::query()->where('owner', HostedService::OWNER_PLATFORM)
                    ->selectRaw('provider, COUNT(*) as count')->groupBy('provider')
                    ->pluck('count', 'provider')->map(fn ($count) => (int) $count)->all(),
            ),
        ]);
    }

    /**
     * Turn a provider on or off and save its settings.
     */
    public function update(Request $request, HostingProviders $providers, string $provider): RedirectResponse
    {
        abort_unless(isset(HostingProviders::PROVIDERS[$provider]), 404);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            ...self::rules(HostingProviders::PROVIDERS[$provider]['fields']),
        ]);

        $providers->update($provider, $validated['enabled'], $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider saved.', ['provider' => HostingProviders::PROVIDERS[$provider]['label']])]);

        return to_route('admin.hosting.index');
    }

    /**
     * Validation rules for a provider's fields.
     *
     * @param  array<string, array{type: string}>  $fields
     * @return array<string, list<string>>
     */
    public static function rules(array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (array $field, string $key) => [$key => match ($field['type']) {
            'number' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'secret' => ['nullable', 'string', 'max:4000'],
            default => ['nullable', 'string', 'max:255'],
        }])->all();
    }
}
