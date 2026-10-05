<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSshKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class DesktopDeviceController extends Controller
{
    /**
     * The computers the user's desktop app is signed in on (DESK-001), most recently used first.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/desktop', [
            'devices' => $request->user()->tokens()
                ->latest('last_used_at')
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Sign the desktop app out on that computer: its token stops working.
     */
    public function destroy(Request $request, int $device): RedirectResponse
    {
        $token = $request->user()->tokens()->findOrFail($device);

        $token->delete();

        // Its SSH key (DESK-008) went with it.
        SyncSshKeys::dispatch($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out “:name”.', ['name' => $token->name])]);

        return back();
    }
}
