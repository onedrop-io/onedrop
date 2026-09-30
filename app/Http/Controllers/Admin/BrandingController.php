<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Sandbox\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The install's name and logo (ADMIN-001).
 */
class BrandingController extends Controller
{
    /**
     * Show the General settings.
     */
    public function edit(): Response
    {
        return Inertia::render('admin/general', [
            'defaultName' => config('app.default_name'),
            'customName' => app(Branding::class)->customName(),
        ]);
    }

    /**
     * Rename the app (blank goes back to the name in `.env`).
     */
    public function update(Request $request, Branding $branding): RedirectResponse
    {
        $branding->rename($request->validate(['name' => ['nullable', 'string', 'max:60']])['name'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Name saved.')]);

        return to_route('admin.general.edit');
    }

    /**
     * Upload a logo.
     */
    public function storeLogo(Request $request, Branding $branding): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp,svg']]);

        $branding->storeLogo($request->file('logo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo saved.')]);

        return to_route('admin.general.edit');
    }

    /**
     * Go back to the droplet.
     */
    public function destroyLogo(Branding $branding): RedirectResponse
    {
        $branding->removeLogo();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo removed.')]);

        return to_route('admin.general.edit');
    }

    /**
     * The logo, for everyone (the sign-in page shows it too). SVGs can't run scripts.
     */
    public function logo(Branding $branding): StreamedResponse
    {
        $path = $branding->logoPath();
        abort_if($path === null, 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
