<?php

use App\Http\Controllers\Api\DesktopActivityController;
use App\Http\Controllers\Api\DesktopDeviceController;
use App\Http\Controllers\Api\DesktopSshKeyController;
use App\Http\Controllers\Api\DesktopTokenController;
use App\Http\Controllers\Api\DesktopTunnelController;
use App\Http\Controllers\Api\SandboxImageController;
use Illuminate\Support\Facades\Route;

// The desktop app's sign-in (DESK-001), and what only it does. With its token, the app also uses the web app's own
// routes (UseDesktopToken).
Route::prefix('v1')->name('api.')->group(function () {
    // The last step of signing in: a one-time code from the browser, and the app's PKCE verifier, for a token.
    Route::post('desktop/token', [DesktopTokenController::class, 'store'])->middleware('throttle:20,1')->name('desktop.token.store');

    // The `sandbox image` workflow, after publishing a new sandbox image (SBX-014): signed by GitHub, not a user.
    Route::post('sandbox-images/published', [SandboxImageController::class, 'published'])->middleware('throttle:20,1')->name('sandbox-images.published');

    Route::middleware(['auth:sanctum', 'throttle:600,1'])->group(function () {
        Route::delete('desktop/token', [DesktopTokenController::class, 'destroy'])->name('desktop.token.destroy');

        // What only the desktop app does (DESK-007..011): its tunnel into a sandbox, its SSH key, this computer as a
        // place to run projects, and what's going on for its menu bar icon.
        Route::post('desktop/projects/{project}/tunnel', [DesktopTunnelController::class, 'store'])->name('desktop.tunnel.store');
        Route::post('desktop/ssh-key', [DesktopSshKeyController::class, 'store'])->name('desktop.ssh-key.store');
        Route::post('desktop/device', [DesktopDeviceController::class, 'store'])->name('desktop.device.store');
        Route::get('desktop/activity', DesktopActivityController::class)->name('desktop.activity');
    });
});
